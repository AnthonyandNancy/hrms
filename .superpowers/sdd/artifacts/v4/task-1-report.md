# Volume 4 Task 1 report — platform page & catalog consistency

Date: 2026-11-27
Agent: v4-task1
Commit: pending (see final message)
Branch: `feat/v2.1-remediation`

## Deliverables

| File | Change |
| --- | --- |
| `docs/audit/2026-11-26-platform-module-disposition.md` | New. 35-row disposition table (15 platform + 20 reporting-family keys), ownership decisions, retirement order, evidence column. |
| `frontend/src/modules/platform/README.md` | New. Platform boundary (IAM, workflow, settings, self-service, benefits, calendar, notification, audit) vs excluded business domains and `modules/reporting`. |
| `frontend/src/modules/platform/catalog.ts` | 34 → 31 configs. Added `module: 'platform' | 'reporting'`; removed stale hub configs for `dashboard/index`, `reports/index`, `notices/index` (those keys resolve from `modules/reporting/pages`). No config points at a non-existent page. |
| `frontend/tests/unit/platformCatalog.spec.ts` | New, 9 tests. |
| `backend/tests/Support/PlatformScenario.php` | New. Real User + Role + RoleCapability + RolePage + UserRole + AuthorityBinding helpers. |

## Inventory result

Registry in scope: **35** component keys = **15 platform** + **20** dashboard/report/notice business keys.

- **Dedicated page files: 17.** 14 are platform-owned config-bearing pages
  (account, six IAM screens, workflow, workflow detail, benefits, calendars,
  settings, notifications, audit); the other 3 are the reporting hubs already
  in `modules/reporting` (dashboard, reports, notices).
- **Generic `PlatformWorkspace` wrappers: 18.**
  - **1 platform-owned:** `self-service` — Task 4 has staged a dedicated page at
    `modules/platform/self-service/pages/SelfServicePage.vue` and must repoint
    the registry key there. During this task the registry still loads
    `modules/platform/pages/SelfServicePage.vue` (the 11-line generic wrapper).
  - **17 reporting-family:** 5 dashboard detail pages, 8 report pages and 4
    notice governance panels. They belong to `modules/reporting` and are marked
    `module: 'reporting'` in the catalog until Task 7 retirement.

So: **18 entries are still generic; exactly 1 of those is platform-owned.**

## Needed edits owned by other agents

1. **`frontend/src/modules/platform/pages/index.ts` + `frontend/src/router/registry.ts`
   (owned by v4-task5 this wave; this task did not touch them).**
   - `RegistryLoader`: `'self-service': () => import('../../platform/self-service/pages/SelfServicePage.vue')`
     or a re-export from `pages/index.ts`. The registry key must not change.
   - `RegistryLoader`: `'dashboard/index'`, `'reports/index'`, `'notices/index'`
     already resolve from `modules/reporting/pages`; no change required.
   - **No loader move is otherwise needed**: all 15 platform registry keys resolve
     today and the new spec asserts each loader returns a component.
2. **Task 7 (v4-B1) retirement** — after Task 4 repoints `self-service`, delete:
   - the 17 reporting wrapper `.vue` files under `modules/platform/pages/`;
   - `components/PlatformWorkspace.vue` and the `views/platform` adapter;
   - the remaining `catalog.ts` entries and `PlatformMode` type;
   - the now-unused `views/platform` references in `scripts/audit_route_coverage.mjs`
     and `scripts/generate_coverage_matrix.mjs` (their platform prefix dir points at
     the adapter).
   Page counts do not change: keys are re-homed, not added or removed.

## Backend scenario support

`Tests\Support\PlatformScenario` is an abstract base (extends `TestCase`, uses
`RefreshDatabase`). It installs a frozen clock (`2026-11-23T09:00:00Z`), flushes
the authorization cache, re-aliases `capability` to the production
`RequireCapability` middleware and binds the real `CapabilityAuthorizer`.

Helpers:

- `userWithRole(string $roleCode, array $anchorIds = []): User` — looks up the
  code-owned template, persists `roles` + `role_capabilities` + `role_pages`,
  creates the `User`, writes `user_roles`, expands every template grant into a
  real `authority_bindings` row (`purpose_code = ROLE_<code>`), and registers a
  synthetic employment mapping in the People directory fake.
- `manager(): User` — `LINE_MANAGER` (MANAGED-anchored workflow read/decide).
- `auditAdmin(): User` — `AUDITOR` (holds `iam.audit.read` and `iam.user.read`,
  no write/admin capability).
- `settingsAdmin(): User` — `SYSTEM_ADMIN` (wildcard COMPANY binding).
- Supporting: `actAs(User)`, `registerEmployment()`, `employmentId()`,
  `managedEmploymentIds()`, `timeActorFor(User)`, `settingsAdminActor()`.

Smoke-verified locally with three temporary tests (removed before commit):
helpers create real rows, `settingsAdmin` passes `/v1/users` through the
production gate, and `auditAdmin` reads `/v1/auth/events` while being denied
`/v1/roles`. `OK (3 tests, 23 assertions)`.

## Verification (real output)

- `pnpm exec vitest run tests/unit/platformCatalog.spec.ts` → **9/9 passed**
  (plus `platformPages.spec.ts` 36/36 in the same run).
- `pnpm typecheck` → exit 0.
- `pnpm lint` → exit 0.
- Wave A backend feature suites
  (`SettingsWorkspaceTest|WorkflowCenterTest|AccountSecurityTest|SelfServiceWorkspaceTest|NotificationAuditWorkspaceTest`)
  → **30 tests, 260 assertions, OK** — the parallel tests that consume this
  fixture stay green.
- `node scripts/audit_page_components.mjs` → `"ok": true`, 155/155,
  `missingMappings: []`, `missingFiles: []`.
- `node scripts/audit_route_coverage.mjs` → `"failures": []`, gap markers 0.
- Backend Pint (new file) → PASS.
- Backend PHPStan → `[OK] No errors`.
- Backend full PHPUnit → **1278 tests, 58,238 assertions, 1 failure**, and the
  failure is **not from this task**:
  `Tests\Contract\ActionManifestParityTest::test_owned_capability_gaps_are_real_and_absent_from_openapi`
  → `Stale capability gap notification.read: OpenAPI GET /v1/engagement/notifications already carries it`.
  The working tree contains another agent's uncommitted route/Capability change
  (`backend/tests/Authorization/endpoint_matrix.php`, `routes/api.php`,
  `docs/openapi/openapi.yaml`) at the same time. Re-run once those land.
- Frontend full unit → **72 files / 599 tests, 1 failure** from
  `tests/unit/platformModulePages.spec.ts`, also not from this task: it asserts
  every non-dedicated platform page still contains the literal
  `<PlatformWorkspace :config="config" />`, but the parallel Task 2 agent has
  already rewritten `pages/AccountSecurityPage.vue` into a dedicated page
  (`account` is still absent from that spec's `DEDICATED_PAGES` set). This task
  did not edit that spec or page.
- Backend Pint full-tree → 4 issues in files owned by other agents
  (`routes/api.php`, `tests/Authorization/endpoint_matrix.php`,
  `tests/Feature/Platform/NotificationAuditWorkspaceTest.php`,
  `tests/Support/EngagementAuthorizer.php`). The new file passes Pint.

## Deviations from the plan

- The plan listed `pages/index.ts` as a Task 1 file. The parallel plan assigns it
  to v4-task5, so it was **not edited**; the required self-service loader change
  is documented above.
- The plan's suggested `PlatformModule` type with `key/label/pageCode/path/...`
  does not match the existing catalog's rendering contract. The retained type is
  `PlatformPageConfig` (with the new `module` field) because every config wrapper
  still passes it to `PlatformWorkspace`. Removing `mode` now would break 18 live
  pages; Task 7 removes it with the shell.
- Three catalog entries (`dashboard/index`, `reports/index`, `notices/index`)
  were removed rather than re-homed: the router resolves those keys from
  `modules/reporting/pages` and no code reads their config.
