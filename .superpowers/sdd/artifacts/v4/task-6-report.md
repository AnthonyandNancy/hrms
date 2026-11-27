# Volume 4 Task 6 report — notifications and audit log consistency

Date: 2026-11-27
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Owner: v4-task6

## Status

DONE for the two page surfaces and their tests. Verified against the real endpoints.
No loader/registry/barrel edit was needed; both keys already map to the corrected files.

## Route verification (real output)

```
cd backend
php artisan route:list --path=notifications
  GET  v1/engagement/notifications                engagement.notifications.index  capability:engagement.notification.read
  POST v1/engagement/notifications/{notification}/read  engagement.notifications.read  capability:engagement.notification.read
  GET  v1/notifications/deliveries               (admin delivery projection)
  POST v1/notifications/deliveries/{id}/retry    (admin retry)
  POST v1/notifications/dispatch                 (admin dispatch)

php artisan route:list --path=auth/events
  GET v1/auth/events  auth.events  mfa.enrolled, capability:iam.audit.read,COMPANY

php artisan route:list --path=audit
  ERROR: no routes matching 'audit' (no audit-export route exists)

php artisan route:list --path=engagement/notices
  14 notice routes; notice read export exists at /v1/engagement/notices/{notice}/reads/export,
  but no generic audit export.
```

## Recorded gaps — confirmed vs refuted

1. **CONFIRMED — server-side audit filters.** `AuthorizationAdminController::authEvents`
   validates only `page` and `page_size`; the query orders by `id` with no date/actor/action/result predicates.
   The page filters only the loaded page and says so in Chinese (`audit-partial`).
2. **CONFIRMED — audit export missing.** No route under `/v1/audit`; only notice-read export exists.
   No export button is rendered; the page states `审计导出暂未开放`.
3. **CONFIRMED — no audit resource column.** The projection has no resource field; the page derives the
   Chinese resource family from the event type and states this only through the label, not a fake server column.
4. **CONFIRMED — notification channel preferences not implemented.** No preference route, table or
   service; the page renders an honest `渠道偏好设置：该功能暂不可用` note and no toggle.
5. **Audit list is itself NOT audited.** The repo writes `auth_events` only for auth/authority mutations,
   and `audit_events` for business writes/sensitive reads; `GET /v1/auth/events` does not record a read.
   Per instructions, no invented audited-read was added.


## Additional consistency gap discovered (reported, not fixed)

The notification page and registry guard on `notification.read`, but the two real
`engagement/notifications` routes are guarded by `engagement.notification.read`.
`RoleTemplateCatalog` issues `notification.read` to the non-wildcard roles
(including demo.hr) and does not issue `engagement.notification.read`; the IAM
authorizer has no alias for the legacy code. Therefore a normal role reaches the
page but the inbox call gets 403. Only wildcard admin roles exercise the page
end-to-end today. Fixing it requires the IAM/route owner to either alias the
legacy route code to `notification.read`, change the route capability, or issue
both bindings; that file is outside this task's owned surface, so this report
records it instead of editing a shared route.

## Endpoints used

- `GET /v1/engagement/notifications` — caller-scoped inbox.
- `POST /v1/engagement/notifications/{notification}/read` — idempotent single mark-read.
- "Mark all" composes the existing per-item command; no bulk route is invented.
- `GET /v1/auth/events?page=&page_size=` — paginated audit projection.

## Changes

- `frontend/src/modules/platform/pages/NotificationsPage.vue`
  - read/unread + business-type filters, Chinese labels, timestamps, read chips;
  - single and all-read actions; all-read reports an explicit partial state on failure;
  - five states (loading/empty/error/forbidden/partial), capability guard `notification.read`;
  - honest channel-preference gap note, no fake toggle, no raw enum/table names.
- `frontend/src/modules/platform/pages/SystemAuditPage.vue`
  - true server pagination via `DataTable` `update:page` / `update:page-size`;
  - actor, Chinese action/resource, result, request id, timestamp columns;
  - raw event code and request id are literal only inside the operator detail dialog (`事件代码`);
  - filters explicitly disclosed as applying to the loaded page only; export gap disclosed;
  - five states and capability guard `iam.audit.read`.
- `frontend/src/modules/platform/api/notifications.ts` (new) — caller-scoped inbox + mark-read normalisation.
- `frontend/src/modules/platform/api/audit.ts` (new) — paginated auth-event normalisation.
- `backend/tests/Feature/Platform/NotificationAuditWorkspaceTest.php` (new, 5 tests / 38 assertions)
  - inbox scoped to caller, no cross-user leak;
  - mark-read idempotent, other user's row 404;
  - audit list requires `iam.audit.read`, regular admin 403;
  - pagination shape and ordering;
  - append-only evidence and read does not create a second event.
- `frontend/tests/unit/NotificationAuditWorkspace.spec.ts` (new, 7 tests)
  - Chinese labels, time/read state, single and all read;
  - honest channel gap;
  - five states for notifications and audit;
  - server page/page_size requests, pagination emits, client-side-only filter disclosure,
  - literal event code only in detail.
- `frontend/tests/e2e/real/notification-audit.spec.ts` (new, 2 tests; collected only).

## Loader edit

None required. `notifications` → `NotificationsPage.vue` and `system/system-audit` → `SystemAuditPage.vue`
were already mapped; registry 155 pages unchanged.

## Gates (real output)

- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never --filter='NotificationAuditWorkspaceTest'`
  → OK (5 tests, 38 assertions).
- `phpunit --colors=never` (full backend)
  → 1279 tests, 2 failures — BOTH in concurrent Task 2 (account security) surface:
  `GapCompletionCapabilityMatrixTest` missing matrix rows for `/v1/auth/mfa/status`, `/v1/auth/sessions`;
  `IamRouteMatrixTest` missing `GET /v1/auth/sessions`. Not caused by Task 6 files.
- `vendor/bin/pint --test` (full) → 4 style issues in concurrent files
  (`AuthController.php`, `routes/api.php`, `tests/Authorization/endpoint_matrix.php`, `ScratchSessionTest.php`);
  `pint --test tests/Feature/Platform/NotificationAuditWorkspaceTest.php` → PASS.
- `vendor/bin/phpstan analyse --no-progress` → OK (no errors).
- `artisan capability:audit` → `Capability audit OK.` (warnings for 4 concurrent account routes only).
- `pnpm exec vitest run tests/unit/NotificationAuditWorkspace.spec.ts` → 7/7 pass.
- `pnpm typecheck` / `pnpm lint` / `pnpm test:unit` — BLOCKED by concurrent uncommitted work:
  - typecheck errors in `api/settings.ts`, `catalog.ts`, `AccountSecurityPage.spec.ts`; zero errors in Task 6 files;
  - lint error in `catalog.ts` (`NOTICE_DESCRIPTION` unused); Task 6 files lint clean;
  - full unit suite: 583/589 pass, 6 failures in `SettingsWorkspace.spec.ts` and
    `platformModulePages.spec.ts` (account page no longer config-bound). Task 6 spec and all platform
    workspace/audit suites pass.
- `node scripts/audit_user_copy.mjs` → ok true.
- `node scripts/audit_ui_primitives.mjs` → ok true.
- `pnpm exec playwright test --config=playwright.real.config.ts --list tests/e2e/real/notification-audit.spec.ts`
  → 2 tests collected. Real execution NOT RUN (no seeded stack / demo password in this session).

## Deviation / dependency notes

- Plan says files under `settings/pages/*`; used corrected paths per `path-alignment.md`.
- No change to `router/registry.ts`, `platform/pages/index.ts`, or `router/pageComponents.ts`.
- `frontend/src/generated/api.ts` was regenerated once because a concurrent OpenAPI edit made it stale;
  regeneration contains only concurrent workflow-description changes, not Task 6 work. It is NOT staged by Task 6.
- Full backend/frontend gates cannot be green while Wave A peers have uncommitted failures; Task 6
  scope is green and the full-suite failures are enumerated above with owning files.
