# FactoryHR V2.1 — Platform module disposition

Date: 2026-11-27
Scope: Volume 4 Task 1 — platform page and catalog consistency
Branch: `feat/v2.1-remediation`

## Why this record exists

Volume 3 Task 16 moved dashboard, report and notice hubs into
`frontend/src/modules/reporting`, and Volume 3 Task 17 replaced six of the
fifteen platform registry entries with purpose-built workspaces. The shared
`PlatformWorkspace.vue` shell still renders the remaining pages, but its `mode`
switch must shrink deterministically and no catalog entry may outlive its
registry page.

This is the disposition record for:

- the **15 platform registry entries** (`layer: 'platform'` in
  `frontend/src/router/registry.ts`);
- the **20 business-registry entries** whose component keys still route through
  the platform module's dashboard/report/notice configs (`dashboard/*`,
  `reports/*`, `notices/*`), all of which now belong to `modules/reporting`.

## Summary

- Registry entries in scope: **35** = 15 platform + 20 reporting-family.
- Platform-owned entries: **15**; reporting-owned entries: **20**.
- Dedicated implementations: **17**; generic `PlatformWorkspace` wrappers: **18**.
- Generic wrappers split as **17 reporting-family** plus **1 platform-owned**
  (`self-service`, which Volume 4 Task 4 replaces with the already-staged page
  under `modules/platform/self-service/pages/`).
- Catalog entries after this task: **31**. The three stale hub configs
  (`dashboard/index`, `reports/index`, `notices/index`) were removed because
  those keys now resolve from `modules/reporting/pages` and no longer use a
  platform config. `workflow/cases/by-id` remains router-owned with no config.

## Disposition table

Statuses: `dedicated` = page owns its layout without `PlatformWorkspace`;
`generic` = thin wrapper that imports `PlatformWorkspace` and a catalog config.
Retirement order `0` means already off the shared shell.

| Entry (componentKey) | Page file | Status | Owning module | Shell retirement order | Evidence |
| --- | --- | --- | --- | --- | --- |
| `account` | `frontend/src/modules/platform/pages/AccountSecurityPage.vue` | dedicated | platform | 0 | `AccountSecurityPage.spec.ts`, `AccountSecurityTest` |
| `system/system-users` | `frontend/src/modules/platform/pages/SystemUsersPage.vue` | dedicated | platform | 0 | `systemUsersPage.spec.ts`, `UserRoleAssignmentApiTest` |
| `system/system-roles` | `frontend/src/modules/platform/pages/SystemRolesPage.vue` | dedicated | platform | 0 | `systemRolesPage.spec.ts`, `RolePagesApiTest` |
| `system/system-menus` | `frontend/src/modules/platform/pages/SystemMenusPage.vue` | dedicated | platform | 0 | `systemGovernancePages.spec.ts`, `MenuOverrideTest` |
| `system/system-permissions` | `frontend/src/modules/platform/pages/SystemPermissionsPage.vue` | dedicated | platform | 0 | `systemGovernancePages.spec.ts`, `MePermissionsTest` |
| `system/system-data-scopes` | `frontend/src/modules/platform/pages/SystemDataScopesPage.vue` | dedicated | platform | 0 | `systemGovernancePages.spec.ts`, `ScopePolicyWriteTest` |
| `system/system-field-policies` | `frontend/src/modules/platform/pages/SystemFieldPoliciesPage.vue` | dedicated | platform | 0 | `systemGovernancePages.spec.ts`, `FieldPolicyEscalationTest` |
| `workflow` | `frontend/src/modules/platform/pages/WorkflowCenterPage.vue` | dedicated | platform | 0 | `WorkflowCenter.spec.ts`, `WorkflowCenterTest` |
| `workflow/cases/by-id` | `frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue` | dedicated | platform | 0 | `WorkflowCenter.spec.ts`, `WorkflowCenterTest` |
| `benefits` | `frontend/src/modules/platform/pages/BenefitsPage.vue` | dedicated | platform | 0 | `platformPages.spec.ts`, benefits API tests |
| `system/system-calendars` | `frontend/src/modules/platform/pages/SystemCalendarsPage.vue` | dedicated | platform | 0 | `platformWorkspaces.spec.ts`, `CalendarAdminTest` |
| `system/system-settings` | `frontend/src/modules/platform/pages/SystemSettingsPage.vue` | dedicated | platform | 0 | `platformWorkspaces.spec.ts`, `SettingsWorkspaceTest` |
| `notifications` | `frontend/src/modules/platform/pages/NotificationsPage.vue` | dedicated | platform | 0 | `platformWorkspaces.spec.ts`, `NotificationAuditWorkspaceTest` |
| `system/system-audit` | `frontend/src/modules/platform/pages/SystemAuditPage.vue` | dedicated | platform | 0 | `platformWorkspaces.spec.ts`, `NotificationAuditWorkspaceTest` |
| `self-service` | `frontend/src/modules/platform/pages/SelfServicePage.vue` | generic | platform | 1 | pending `SelfServicePage.spec.ts`; Task 4 owns replacement |
| `dashboard/index` | `frontend/src/modules/reporting/pages/DashboardPage.vue` | dedicated | reporting | 0 | `platformPages.spec.ts` |
| `dashboard/departments` | `frontend/src/modules/platform/pages/DashboardDepartmentsPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `dashboard/workforce` | `frontend/src/modules/platform/pages/DashboardWorkforcePage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `dashboard/changes` | `frontend/src/modules/platform/pages/DashboardChangesPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `dashboard/tasks` | `frontend/src/modules/platform/pages/DashboardTasksPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `dashboard/recruitment` | `frontend/src/modules/platform/pages/DashboardRecruitmentPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/index` | `frontend/src/modules/reporting/pages/ReportsPage.vue` | dedicated | reporting | 0 | `platformPages.spec.ts` |
| `reports/headcount` | `frontend/src/modules/platform/pages/ReportHeadcountPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/attendance` | `frontend/src/modules/platform/pages/ReportAttendancePage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/payroll` | `frontend/src/modules/platform/pages/ReportPayrollPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/hiring` | `frontend/src/modules/platform/pages/ReportHiringPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/talent` | `frontend/src/modules/platform/pages/ReportTalentPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/builder` | `frontend/src/modules/platform/pages/ReportBuilderPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/subscriptions` | `frontend/src/modules/platform/pages/ReportSubscriptionsPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `reports/exports` | `frontend/src/modules/platform/pages/ReportExportsPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `notices/index` | `frontend/src/modules/reporting/pages/NoticesPage.vue` | dedicated | reporting | 0 | `platformPages.spec.ts` |
| `notices/approval` | `frontend/src/modules/platform/pages/NoticeApprovalPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `notices/audience` | `frontend/src/modules/platform/pages/NoticeAudiencePage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `notices/reads` | `frontend/src/modules/platform/pages/NoticeReadsPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |
| `notices/templates` | `frontend/src/modules/platform/pages/NoticeTemplatesPage.vue` | generic | reporting | 2 | `platformPages.spec.ts` |

## Final ownership decisions

- **Platform** (`modules/platform`): IAM administration (account security,
  users, roles, menus/pages, permissions, data scopes, field policies), workflow
  governance (approval center and case detail), employee self-service, benefits,
  company calendar, system settings/dictionary/parameters/files, notifications,
  and audit/operations evidence.
- **Reporting** (`modules/reporting`): dashboard family, report catalog and all
  report views/actions, and the notice (engagement) workspace and governance
  panels. `modules/platform` must not import reporting page components.
- Base pages (`/account/mfa`, `/account/sessions`, login, workspace, forbidden,
  not-found) remain base-layer registry entries, not platform module pages.

## Shared-shell retirement order

`PlatformWorkspace.vue` is deleted only after its remaining references are
zero. Intended order, aligned with the Wave A/B parallel plan:

1. **Task 4** replaces `self-service` with the staged dedicated page under
   `modules/platform/self-service/pages/SelfServicePage.vue` and deletes the
   `self-service` catalog entry. This is the last platform-owned generic shell;
   after it lands, no platform-owned page needs the shell.
2. **Reporting follow-up (after Tasks 5/6)** migrates the remaining 16
   dashboard/report/notice wrapper pages into `modules/reporting`. The three
   stale hub configs (`dashboard/index`, `reports/index`, `notices/index`) were
   already removed by this task.
3. **Task 7** deletes `PlatformWorkspace.vue`, the `views/platform` adapter, the
   wrapper `.vue` files and the now-empty catalog, then updates the platform
   barrel, `pageComponents.ts` and removes the `mode` type entirely.

Until step 3, the catalog remains the only source of `mode` values and
`platformCatalog.spec.ts` asserts every mode still has a live branch in the
shell. No new `mode` may be introduced.

## Verification

`frontend/tests/unit/platformCatalog.spec.ts` asserts:

- every platform registry key resolves to an existing lazy page file;
- no catalog entry references a page absent from the registry, and the retired
  notice form routes and the old workspace key stay absent;
- the disposition table lists exactly the 35 registry entries in scope, with
  existing page files and only `dedicated`/`generic` statuses, and exactly the
  15 platform-owned entries carry owner `platform`, and the 14 of them with a
  settings config match catalog entries marked `module: 'platform'` (the
  hidden workflow detail page is router-owned);
- every catalog `mode` still exists in `PlatformWorkspace.vue`.
