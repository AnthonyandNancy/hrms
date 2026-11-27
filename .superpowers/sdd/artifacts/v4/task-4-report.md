# Volume 4 Task 4 — self-service portal

Date: 2026-11-27
Agent: v4-task4
Status: implemented; committed. Final full-suite gates are blocked by another wave's in-flight files (see "Gate evidence").

## Real endpoints verified

`php artisan route:list --path=me` on this worktree lists:

- `GET /v1/me/workspace` — self projection (IAM `WorkspaceController@show`, capability `iam.session.manage`, anchor `SELF`).
- `GET /v1/me`, `GET /v1/me/capabilities`, `GET /v1/me/permissions`.
- `GET /v1/tasks/my` — pending workflow tasks assigned to the caller (`assignee_user_id = principal`; capability `workflow.case.read`).
- `GET /v1/time/attendance` (SELF month view), `GET /v1/time/leave/requests`, `GET /v1/time/leave/balances`, `GET /v1/time/overtime/requests`, `GET /v1/time/overtime/timeoff-balances`.
- `GET /v1/payroll/payslips` and `GET /v1/payroll/payslips/{id}` (self-only; cross-employee detail 404).
- `GET /v1/training/programs`, `GET /v1/training/enrollments`, `GET /v1/training/records`.

No new route was added.

## Backend gap fixed (genuine)

`WorkspaceController::emptyProjection()` previously returned `name: 'User '.$userId` and `initials: 'U'`. That invented a display name for an account with no employee record. It now returns `name: ''` and `initials: '—'`, so the frontend can render an honest "not linked" state and never show a fabricated identity. Signature simplified to `emptyProjection()`.

## Frontend

Created:

- `frontend/src/modules/platform/self-service/pages/SelfServicePage.vue`
- `frontend/src/modules/platform/self-service/components/MyTasks.vue`
- `frontend/src/modules/platform/self-service/components/MyProfile.vue`
- `frontend/src/modules/platform/self-service/api.ts`

Behaviour:

- Profile fields are allow-listed from `GET /v1/me/workspace`; sensitive/unknown keys are dropped in `api.ts`. `MyProfile` checks key presence, so an omitted field renders **无权查看** (not blank, not guessed). Explicit `null` renders 未登记.
- `MyTasks` reads `/v1/tasks/my` only when `workflow.case.read` is present; each task shows title, case number, due time and links to `/workflow/cases/{caseId}`. Without the capability it shows a Chinese explanation and never issues the API call.
- Self-service entries are capability-guarded. Missing capability hides the entry. A visible capability whose destination route needs another capability (payslip page needs `payroll.run.read`) is rendered disabled with the Chinese reason instead of a dead link. `/self-service` itself is still governed by `iam.session.manage`.
- No employee picker, no org-wide list, no admin links or menus inside the page.
- States rendered: loading (`正在加载员工自助数据`), empty (no employment / no tasks / no payslips), error (`员工自助门户暂时无法加载` + request id + retry), forbidden (`无权查看员工自助门户`), partial (workspace loaded, task read failed → `部分数据暂不可用`).
- Every API payload is caller-scoped; the client sends no employment/user id. A request-supplied `?employment_id=` on `/v1/me/workspace` is ignored by the controller and covered by a test.

## Loader edit needed (owned by A5 / lead — NOT made)

`frontend/src/modules/platform/pages/index.ts` currently still maps:

```ts
'self-service': () => import('./SelfServicePage.vue'),
```

and that file remains the generic `PlatformWorkspace` shell. Required edit:

```diff
-  'self-service': () => import('./SelfServicePage.vue'),
+  'self-service': () => import('../self-service/pages/SelfServicePage.vue'),
```

Also needed in the same registry-owner commit (`frontend/tests/unit/platformModulePages.spec.ts`): add `'self-service'` to `DEDICATED_PAGES`, and keep the pinned counts unchanged (no page added/removed — key still `self-service`, 155 pages intact). `frontend/src/router/registry.ts` needs no change for this task: the page already exists as `governance` with `capability: 'iam.session.manage'`. The menu label/group (`系统管理` / `员工自助门户`) is registry metadata owned elsewhere; this page itself contains no admin menu.

Because the loader edit is not made this wave, the route is exercised by the unit spec through a direct component import. The existing config-driven shell test (`platformModulePages.spec.ts`) still fails for a different key (`account`), which is another agent's in-flight change.

## Tests

- `backend/tests/Feature/IAM/SelfServiceWorkspaceTest.php` (new, 4 tests / 41 assertions): own profile only; second user cannot read the first user's workspace even with `?employment_id`; tasks scoped to assignee; no-employee account gets an honest empty projection.
- `frontend/tests/unit/SelfServicePage.spec.ts` (new, 9 tests): profile fields + 无权查看; task list + `/workflow/cases/88` link; missing payslip amount never becomes ¥0.00; capability-hidden entries and no task call; disabled entry with Chinese reason; loading; no-employment empty; forbidden; server error with request id; partial.
- `frontend/tests/e2e/real/self-service.spec.ts` (new, 1 test) — collected with `--list`. Real execution requires a seeded stack and `HRMS_DEMO_PASSWORD`; not run here.

## Gate evidence (real output)

Run from `backend` with `../.tools/php/php.exe -d memory_limit=1G`:

- `phpunit --filter='SelfServiceWorkspaceTest'` → **OK (4 tests, 41 assertions)**.
- `phpunit --filter='NoActiveEmploymentTest|WorkspaceProjectionTest'` → **OK (4 tests, 61 assertions)**.
- `phpstan analyse --no-progress` → **OK, No errors**.
- `capability:audit` → **Capability audit OK.**
- `pint --test app/Modules/IAM/Http/Controllers/WorkspaceController.php tests/Feature/IAM/SelfServiceWorkspaceTest.php` → **PASS (2 files)**.
- Full `phpunit` → **1281 tests, 58738 assertions, 2 failures**, both caused by another agent's new `/v1/auth/sessions` and `/v1/auth/mfa/status` routes missing from `tests/Authorization/endpoint_matrix.php` (`IamRouteMatrixTest`, `GapCompletionCapabilityMatrixTest`). Not self-service. No `artisan test` was used.
- Full `pint --test` → 1 style issue: `routes/api.php` line_ending, introduced by another agent's in-flight route edits.

Run from `frontend`:

- `vitest run tests/unit/SelfServicePage.spec.ts` → **9 passed**.
- `eslint src/modules/platform/self-service tests/unit/SelfServicePage.spec.ts tests/e2e/real/self-service.spec.ts` → **exit 0**.
- `playwright test --config=playwright.real.config.ts --list tests/e2e/real/self-service.spec.ts` → **1 test collected**.
- `pnpm typecheck` → fails on pre-existing/other-agent files: `src/modules/platform/api/settings.ts` (`whole`/`fraction` possibly undefined), `src/modules/platform/catalog.ts` (`NOTICE_DESCRIPTION` unused), `tests/unit/AccountSecurityPage.spec.ts` (`.exists` on `DOMWrapper`). None are self-service files; a temporary type probe of the new API/components compiled cleanly.
- `pnpm lint` → fails only on `src/modules/platform/catalog.ts` `NOTICE_DESCRIPTION` (other agent's in-flight edit). Our files lint clean.
- `pnpm test:unit` → **71 files passed / 596 tests passed; 1 failed**: `platformModulePages.spec.ts` — `account` key still expects the generic shell while `AccountSecurityPage.vue` is being rewritten by Task 2. Self-service spec passes in the same run.

## Gaps / deviations

- No backend endpoint exists for a caller-scoped "my training records" read: `/v1/training/records` is guarded by `training.record.read,COMPANY` and `TrainingLifecycleService::records()` without an `employment_id` returns company-wide rows. The page therefore only links to the `/training` entry (capability `training.program.read`); it does not render training data itself, avoiding a cross-employee leak.
- No employee-scoped payslip list route is reachable for ordinary employees at `/payroll/my-payslips`: the registry has no such route, and `/payroll/payslips` requires `payroll.run.read`. The page shows the caller's own published payslips from `/v1/me/workspace` (self-scoped) and renders the page entry disabled with a Chinese reason when `payroll.run.read` is absent rather than linking to a page that would 403.
- `/v1/time/attendance` returns the caller's own month (SELF); `/v1/time/leave/requests`, `/v1/time/overtime/requests` are SELF by default. Entri
es reuse those existing workspaces; no ids are accepted from the self-service page.
- Registry key remains `kind: governance` with menu group `系统管理`. Changing kind/menu is a registry edit owned by another agent; no second page or key was added.
- Real Playwright execution was not possible (no seeded stack / demo password in this environment); `--list` only.
- Files outside this task that were already modified in the shared worktree (Task 2 account security, Task 6 notifications/audit, route/OpenAPI/catalog changes) were not touched, staged or committed by this task. `frontend/tests/unit/platformModulePages.spec.ts` was reverted after an exploratory edit.

## Commit

`feat(platform): build the self-service portal` — explicit paths only:
`backend/app/Modules/IAM/Http/Controllers/WorkspaceController.php`,
`backend/tests/Feature/IAM/SelfServiceWorkspaceTest.php`,
`frontend/src/modules/platform/self-service/{api.ts,pages/SelfServicePage.vue,components/MyProfile.vue,components/MyTasks.vue}`,
`frontend/tests/unit/SelfServicePage.spec.ts`,
`frontend/tests/e2e/real/self-service.spec.ts`,
`.superpowers/sdd/artifacts/v4/task-4-report.md`.
