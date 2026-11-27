# Volume 3 Task 17 — Platform and System Administration Workspaces Report

## Status

**Implemented (remaining, non-IAM half) and verified.** The six IAM pages were
already delivered and were not recreated or wrapped. This task replaced the five
remaining 11-line `PlatformWorkspace` wrappers with purpose-built pages:

- `WorkflowCenterPage.vue` (`/workflow`)
- `SystemCalendarsPage.vue` (`/system/calendars`)
- `SystemSettingsPage.vue` (`/system/settings`)
- `NotificationsPage.vue` (`/notifications`)
- `SystemAuditPage.vue` (`/system/audit`)

A new workflow case detail page and one registry detail entry were added:
`frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue` at
`/workflow/cases/:id` (`kind: 'detail'`, `menu: null`, `detailOf: 'workflow'`).

Each page uses `PageShell`, renders the five states (loading, empty, error,
forbidden, partial), uses Chinese presentation labels and capability-guarded
actions, and no longer imports `PlatformWorkspace`, `platformPageConfig` or a
`mode ===` switch. No static rows are used as data.

## Commit

`f9bea2fab5bad4f3c408ebf156a4306f4433c599` — `feat(platform): build workflow, calendar, settings, notification and audit workspaces`

Explicit staged paths only (no `git add -A`):

- `frontend/src/modules/platform/api/index.ts` (new)
- `frontend/src/modules/platform/labels.ts` (new)
- `frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue` (new)
- `frontend/src/modules/platform/pages/WorkflowCenterPage.vue`
- `frontend/src/modules/platform/pages/SystemCalendarsPage.vue`
- `frontend/src/modules/platform/pages/SystemSettingsPage.vue`
- `frontend/src/modules/platform/pages/NotificationsPage.vue`
- `frontend/src/modules/platform/pages/SystemAuditPage.vue`
- `frontend/src/modules/platform/pages/index.ts`
- `frontend/src/router/registry.ts`
- `frontend/src/views/platform/index.ts`
- `frontend/src/components/DataTable.vue`
- `frontend/tests/unit/platformWorkspaces.spec.ts` (new)
- `frontend/tests/e2e/real/platform-workspaces.spec.ts` (new)
- pinned-count tests/scripts listed below

## Registry and count updates

Registry contract intentionally changes from 161 to 162 entries:

- registry entries: 161 → 162
- module-mapped pages: 155 → 156
- detail pages: 12 → 13
- menu-bearing pages: unchanged at 143 (the detail page has `menu: null`)
- workspace pages: unchanged at 129
- governance pages: unchanged at 14
- business layer: unchanged at 141
- platform layer: 14 → 15 (the new detail entry is a platform-layer route)
- base pages: unchanged at 6
- clustered gap pages: unchanged at 63 (the new detail entry carries no cluster)

Updated pins:

- `frontend/tests/unit/navigation.spec.ts`
- `frontend/tests/unit/pageComponentUniqueness.spec.ts`
- `frontend/tests/unit/pageModel.spec.ts`
- `frontend/tests/unit/routeRegistry.spec.ts`
- `frontend/tests/unit/shellMenu.spec.ts` (comment only; menu count stays 143)
- `frontend/tests/unit/platformModulePages.spec.ts` (33 → 34 platform keys/files)
- `frontend/tests/unit/platformPages.spec.ts` (legacy adapter now skips
  configless purpose-built keys instead of throwing)
- `scripts/audit_page_components_lib.mjs`
- `scripts/audit_route_coverage.mjs`
- `scripts/tests/audit_page_components.test.mjs`

## Endpoint verification (`php artisan route:list`)

Every consumed endpoint exists; none were invented:

| Page | Method and path |
| --- | --- |
| Workflow list | `GET /v1/cases`, `GET /v1/tasks/my` |
| Workflow detail | `GET /v1/cases/{id}`, `GET /v1/cases/{id}/timeline`, `POST /v1/cases/{caseId}/decisions` |
| Calendar | `GET /v1/time/calendars`, `POST /v1/system/calendars/publish` |
| Settings | `GET/PUT /v1/system/parameters`, `GET /v1/platform/features`, `PUT /v1/platform/features/{flag}` |
| Notifications | `GET /v1/engagement/notifications`, `POST /v1/engagement/notifications/{notification}/read` |
| Audit | `GET /v1/auth/events` |

Route middleware/capabilities verified:

- `workflow.case.read` for case/timeline/tasks reads; `workflow.case.decide`
  for decisions.
- `time.calendar.read` for calendar read; publish command enforces
  `time.calendar.manage`.
- `system.parameter.manage` for parameter read/write.
- `platform.feature.manage` for feature flag read/write.
- `engagement.notification.read` for own inbox list/mark-read.
- `iam.audit.read` for auth/security events.

## Missing endpoint/filter capabilities (reported, not faked)

1. **Audit filtering.** `GET /v1/auth/events` supports only `page` and
   `page_size`. It does not support date, actor, action or result filters, and
   it returns authentication/authorization security events rather than a generic
   `audit_events` projection. The page now states explicitly that its filters
   apply only to the loaded 100-record projection and that server-side
   cross-page filtering is not available. No query parameters are invented.
2. **Audit resource/export.** The auth-events projection has no resource column
   and there is no audit-export route. Resource labels are derived from the
   event type family, and no export button is rendered.
3. **Calendar version history.** The API exposes only the current calendar
   snapshot and its version. It has no version-history endpoint. The page shows
   the current version, says history is retained by audit evidence, and does not
   fabricate version rows.
4. **Calendar publish response.** Publish returns
   `version/timezone/workweek/standard_day_minutes/holidays` but no `year`, so the
   page re-reads `GET /v1/time/calendars?year=` after publishing.
5. **Notification channel preferences.** Existing routes support inbox
   list/mark-read and admin delivery/template operations only. The page renders
   list/mark-read and explicitly says preferences are maintained in the account
   center; it does not render a fake preference editor.
6. **Dictionary editor.** This task's remaining settings wrapper is the
   **参数** workspace. Type-safe parameter editing is implemented against
   `system.parameter.manage`; there is no safe generic dictionary-create flow in
   this page, and no arbitrary key/value editor was added.

## What was built

### Workflow center and detail

- View tabs: 待我审批 / 我发起的 / 全部可见, plus status and type filters
  (rendered as Chinese chips to keep the UI primitive gate green).
- Case list reads the real server-side filters (`view`, `status`, `type`) and
  personal task SLA/due columns from `GET /v1/tasks/my`.
- Case numbers, request titles, statuses, effect statuses and SLA states are
  Chinese labels; unknown codes never leak raw enum text.
- Detail page renders the case summary, payload summary, current node and
  template version, approval timeline (`WORKFLOW_EVENT`, `DECISION`, `EFFECT`),
  and decision controls only when the case is open, assigned to the caller and
  the caller holds `workflow.case.decide`.
- Decision action uses the real body `{action, comment}` and an
  `Idempotency-Key`; APPROVE / RETURN / REJECT are supported.
- Closed/approved/rejected cases are read-only with an explicit reason.
- Both pages handle loading, empty, error, forbidden and partial states.

### Calendar workspace

- Year navigator, current version, timezone, workweek, standard day minutes and
  holiday/MAKEUP snapshot from `GET /v1/time/calendars?year=`.
- Holiday table uses the shared `DataTable`; type and source are Chinese labels.
- Publish is available only with `time.calendar.manage`; it edits workweek,
  standard minutes, timezone and holiday rows, opens an explicit confirmation,
  posts the real publish payload with an `Idempotency-Key`, then re-reads the new
  current version.
- Missing version-history and snapshot-replacement semantics are disclosed.

### Settings workspace

- Feature flags read from `GET /v1/platform/features` and changed through
  `PUT /v1/platform/features/{flag}` with a mandatory reason of at least eight
  characters.
- Type-safe parameter list from `GET /v1/system/parameters`; only existing
  registered keys can be edited. Number, boolean, text and sensitive/masked
  kinds get appropriate editors. Sensitive values are never displayed and must
  be replaced rather than echoed.
- Writes send `{key, scope, value, sensitive}` plus an `Idempotency-Key`.
- Flags and parameters have independent loading/forbidden/error/empty states, so
  one unavailable projection does not blank the other; the page marks the
  combined view partial.

### Notifications workspace

- Real caller inbox from `GET /v1/engagement/notifications`; filters for
 全部/未读/已读.
- Mark one read via `POST /v1/engagement/notifications/{id}/read`, with a
  sequential mark-all action over the actually unread items.
- Notification types and summaries are Chinese; links resolve only from real
  payload identifiers (`case_id`, `notice_id`) and never invent a destination.
- No channel-preference UI is shown; the limitation is stated.

### Audit workspace

- Real `GET /v1/auth/events` projection with time, actor, action, resource
  family, result, request id and metadata detail.
- Result/action/resource labels are Chinese; raw enum codes are not rendered.
- Actor/action/result/date filters are applied to the loaded projection only,
  and the page carries a persistent partial-state disclosure naming the missing
  server filter contract.
- Audit detail opens in a dialog with structured metadata; no raw JSON dump.

## Capability guards

- `workflow.case.read` opens the list/detail; decisions additionally require
  `workflow.case.decide` and `is_awaiting_me`.
- `time.calendar.read` opens calendars; publish requires
  `time.calendar.manage`.
- `system.settings.manage` opens settings; feature flags require
  `platform.feature.manage`; parameters require `system.parameter.manage`.
- `notification.read` opens the inbox.
- `iam.audit.read` opens audit logs.

When a capability is absent the requests are not made and an explicit Chinese
forbidden panel is rendered.

## Tests and gates

Commands run and real results:

- `frontend`: `pnpm exec vitest run tests/unit/platformWorkspaces.spec.ts` —
  **14 passed**.
- `frontend`: `pnpm typecheck` — **passed**.
- `frontend`: `pnpm lint` — **passed (0 warnings)**.
- `frontend`: `pnpm test:unit` — **66 files, 544 tests passed**.
- `frontend`: `VITE_API_MODE=http pnpm build` — **passed** (default build without
  the env var correctly fails closed by design).
- `frontend`: `pnpm exec playwright test --config=playwright.real.config.ts --list tests/e2e/real/platform-workspaces.spec.ts`
  — **5 tests listed** (real-stack execution requires the seeded stack and
  password; this worktree environment did not have them running).
- `backend`: `phpunit --colors=never` — **OK (1246 tests, 58335 assertions)**.
- `backend`: `pint --test` — **PASS (1190 files)**.
- `backend`: `phpstan analyse` — **OK, no errors**.
- `backend`: `php artisan capability:audit` — **Capability audit OK**.
- `backend`: `php artisan arch:test` — **Module boundaries OK** (4 non-blocking
  pre-existing dynamic-table warnings).
- `node deploy/scripts/openapi-lint.mjs` — **OK (259 paths, 334 operations)**.
- `pnpm openapi:validate` — **valid** (2 pre-existing Redocly warnings).
- `node scripts/audit_page_components.mjs` — **exit 0**, `total=162`,
  `mapped=156`, `baseMapped=6`, no missing/stale mappings.
- `node scripts/audit_page_model.mjs` — **exit 1** on pre-existing legacy pages
  (`hr-employees-create`, `hr-departments-editor`, etc.); no failure references
  the new Task 17 pages.
- `node scripts/audit_ui_primitives.mjs` — **exit 1** on pre-existing legacy raw
  controls; no failure references the new Task 17 pages.

## Notes

- `PlatformWorkspace` remains in use by dashboard/reports/notices/account and
  other untouched platform pages; it was not removed globally.
- `frontend/src/modules/talent/**` and `backend/app/Modules/Talent/**` were not
  touched by this task. Concurrent performance-workspace changes visible in the
  working tree were left unstaged.
- The legacy `@/views/platform` adapter now skips keys without a catalog config
  instead of throwing at import time, because those keys are resolved by the
  router directly from `PAGE_COMPONENTS`.
