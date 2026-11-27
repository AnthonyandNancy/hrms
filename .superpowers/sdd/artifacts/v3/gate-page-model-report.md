# Volume 3 — Page-model product gate report

## 1. Scope

- Removed the seven action-like form entries from `frontend/src/router/registry.ts` per ADR-0035:
  `/hr/employees/create`, `/hr/departments/editor`, `/hr/positions/editor`, `/hr/contracts/editor`,
  `/recruitment/requests/create`, `/notices/editor`, `/notices/archive`.
- Registry contract is now **155 entries = 134 business + 15 platform + 6 base**,
  **136 menu-bearing pages**, **18 menu groups**; kinds are **122 workspace + 13 detail + 14 governance + 6 base**.
- `PAGE_REGISTRY` yields **155 component keys**, and `PAGE_COMPONENTS` maps all of them:
  **149 module pages + 6 base views = 155**, with no missing or stale mapping.

## 2. Form routes became actions (and are genuinely reachable)

| Removed route | Replacement action surface |
| --- | --- |
| `/hr/employees/create` | `EmployeeCreateDrawer.vue`, hosted by `EmployeeListPage.vue` |
| `/hr/departments/editor` | `DepartmentFormDrawer.vue`, hosted by `DepartmentWorkspacePage.vue`; the registry key `hr/departments/index` now loads that workspace via `DepartmentTreePage.vue` |
| `/hr/positions/editor` | `PositionFormDrawer.vue`, hosted by `PositionWorkspacePage.vue`; `hr/positions/index` now loads that workspace via `PositionCatalogPage.vue` |
| `/hr/contracts/editor` | `ContractFormDrawer.vue`, hosted by `ContractWorkspacePage.vue`; `hr/contracts/index` now loads that workspace via `ContractLedgerPage.vue` |
| `/recruitment/requests/create` | `RequisitionFormDrawer.vue`, hosted by `RequisitionWorkspacePage.vue`; `recruitment/requests/index` now loads that workspace via `RequisitionListPage.vue` |
| `/notices/editor` | `NoticeComposer.vue`, hosted by reporting `NoticesPage.vue` (`notices/index`) |
| `/notices/archive` | `PlatformWorkspace` archive/restore actions on the notice-management surface; the public/reader notice workspace no longer offers retraction or archive |

The four rewired wrappers are one-component shells so the registry keys did not change while the
dedicated workspaces and their drawers became the live route target. Their unit suites
(`DepartmentWorkspace.spec.ts`, `PositionWorkspace.spec.ts`, `ContractWorkspace.spec.ts`,
`RecruitmentWorkspace.spec.ts`) remain intact and green.

## 3. Cross-module tabs

`PeopleWorkspace.vue` no longer renders the cross-module local navigation (员工档案 / 部门管理 /
岗位管理 / 员工异动 / 劳动合同), nor its `onNavClick` and nav CSS. The component remains because
legacy registry wrappers still resolve through it; `openEmployment()` still uses the router.

## 4. Count pins and generated contracts updated

- Frontend tests: registry/page-model/navigation/shell-menu/component-uniqueness/module-page suites
  now pin 155 / 134 / 136 / 149 and the 35+35+32 module split. Removed form-page behavior tests were
  retargeted to the action components rather than deleted.
- Scripts: `audit_page_components_lib.mjs` (155), `audit_route_coverage.mjs` (155/133/62, K11=14),
  `scripts/lib/clusterMembership.mjs` (notice archive removed), `generate_route_disposition.mjs`
  (155/134/15/6), `generate_registry_entries.py` (133 business pages).
- Backend: `PermissionResourceCatalog` has **153 page resources** (133 business + 14 platform + 6 base);
  the seven form rows were removed and their write APIs/actions were re-homed on the owning
  workspace rows. IAM count tests now pin 153.
- Action manifest: `docs/generated/action-input.json` now carries the seven re-homed actions.
  Generated manifest: **155 pages, 16 write pages, 15 pages with actions, 34 actions,
  149 explicit write gaps, 14 owned capability gaps, 1 public write exception**.

## 5. Verification (fresh run)

| Gate | Command | Result |
| --- | --- | --- |
| Page model | `node scripts/audit_page_model.mjs` | **exit 0**, `{"ok":true,"failures":[]}` |
| Page components | `node scripts/audit_page_components.mjs` | **exit 0**, `total=155 expected=155 mapped=149 moduleMapped=149 baseMapped=6 mappedIncludingBase=155` |
| Route coverage | `node scripts/audit_route_coverage.mjs` | **exit 0**, inventory 133 + 14 + 6; registry 155 (134/15/6); clusters 62, drift 0 |
| Action manifest | `node scripts/audit_action_manifest.mjs` | **exit 0** (counts above) |
| Frontend typecheck | `pnpm typecheck` | **exit 0** |
| Frontend lint | `pnpm lint` | **exit 0**, zero warnings |
| Frontend unit | `pnpm test:unit` | **exit 0**, 66 files / 545 tests |
| Backend PHPUnit | `vendor/bin/phpunit` | **exit 0**, 1246 tests / 58376 assertions |
| Backend Pint | `vendor/bin/pint --test` | **exit 0**, 1190 files |
| Backend PHPStan | `vendor/bin/phpstan analyse` | **exit 0**, no errors |
| Capability audit | `php artisan capability:audit` | **exit 0** |
| Architecture test | `php artisan arch:test` | **exit 0**, 4 non-blocking warnings |
| OpenAPI lint | `node deploy/scripts/openapi-lint.mjs` | **exit 0**, 259 paths / 334 operations |
| OpenAPI validate | `pnpm openapi:validate` | **exit 0**, valid with 2 pre-existing warnings |

## 6. Unresolved / follow-ups

- Notice **edit** still has no backend `PATCH /v1/engagement/notices/{id}` endpoint; the composer covers
  create and the archive/restore actions cover governance. This is a backend contract gap, not a page.
- Reader-side notice archive was intentionally removed from `NoticesPage`; archive/restore remain on the
  management workspace. A future reader-facing archive view would require its own read contract.
- `docs/audit/2026-11-26-v2-action-gaps.json` measurement prose still records Volume-2-era historical
  counts; the current audited contract is the generated manifest shown above.
