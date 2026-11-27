# Platform module

This module owns the **governance and administration surfaces** of FactoryHR.
It is a composition module: it may read IAM, Workflow, Time and Platform
contracts, but it never becomes a second home for a business domain.

## What belongs here

| Surface | Pages | Why platform |
| --- | --- | --- |
| Account security | `pages/AccountSecurityPage.vue` | Session, password and optional MFA posture belong to the authenticated principal, not to a business domain. |
| IAM administration | `pages/SystemUsersPage.vue`, `SystemRolesPage.vue`, `SystemPagesPage.vue` | These screens edit the capability/role/page model itself. They must stay next to the IAM contracts. |
| Workflow governance | `pages/WorkflowCenterPage.vue`, `workflow/pages/WorkflowCaseDetailPage.vue` | Approval cases are cross-domain platform infrastructure; a case timeline is not owned by People or Time. |
| Employee self-service | `@/views/WorkspaceView.vue` with typed readers from `self-service/api.ts` | Self-service is a section of the workspace. It aggregates the caller's own cross-domain facts and links and owns no domain writes. |
| Benefits administration | `pages/BenefitsPage.vue` | Statutory benefit plans and enrollments are company-wide settings, not a People profile tab. |
| Company calendar | `components/SettingsCalendarTab.vue` | One company calendar, holidays and workweek are edited inside the single `/system/settings` workspace. |
| Settings | `pages/SystemSettingsPage.vue` | Dictionaries, parameters and file metadata are platform configuration. |
| Notifications | `pages/NotificationsPage.vue` | Delivery/inbox plumbing is platform infrastructure; business modules only emit events. |
| Audit and operations | `pages/SystemAuditPage.vue` | Authentication events, access review and sensitive-operation evidence are platform controls. |

Page registration lives in `pages/index.ts`; the router resolves those lazy
loaders through `router/pageComponents.ts`. The page-code registry in
`router/registry.ts` remains the single source of routes, menus, capabilities
and required APIs.

## What does not belong here

- **Business domains.** Employees, departments, positions, contracts, time,
  leave, overtime, travel, payroll, performance, recruitment and training keep
  their own modules under `frontend/src/modules/*`. A platform page may link to
  them; it must not import their page components (the module-boundary gate
  enforces this).
- **Reporting and engagement.** Dashboard, report catalog/views/exports and the
  notice workspace plus its governance panels live in
  `frontend/src/modules/reporting`. The legacy dashboard/report/notice wrappers
  still under `pages/` are scheduled for migration and are marked
  `module: 'reporting'` in `catalog.ts`; do not add new reporting pages here.
- **Base authentication framing.** Login, workspace, forbidden, not-found and
  the `/account/mfa` + `/account/sessions` views are base-layer pages
  (`src/views`), not platform module pages.

## Catalog rules

`catalog.ts` describes only pages whose implementation is still (or was
recently) the config-driven `components/PlatformWorkspace.vue`. Every entry must:

1. use a `key` that exists in `PAGE_REGISTRY` and in `pages/index.ts`;
2. declare its final `module` (`platform` or `reporting`);
3. use a `mode` that still has a live branch in `PlatformWorkspace.vue`;
4. omit retired pages — no notice editor/archive form routes (ADR-0035), no
   `platform-workspace` key and no config for a page that only resolves from
   `modules/reporting/pages`.

`frontend/tests/unit/platformCatalog.spec.ts` enforces these rules and cross
checks this module against
`docs/audit/2026-11-26-platform-module-disposition.md`.

## Retirement status

The shared shell is transitional. Each remaining `PlatformWorkspace` mode is
itself a platform-owned object workspace; the generic reporting wrappers are
awaiting migration. The exact order for deleting
`PlatformWorkspace.vue` is recorded in the disposition document. Do not add a
new `mode`, a new wrapper, or a second registry entry while that retirement is
in progress.
