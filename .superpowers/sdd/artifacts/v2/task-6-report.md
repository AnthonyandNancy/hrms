# Volume 2 Task 6 — 单公司用户管理工作台报告

**Status:** DONE (with documented API-contract gaps)

## Scope and delivered files
- `frontend/src/modules/platform/pages/SystemUsersPage.vue` — rebuilt list workbench: search, status/role filters, pagination, empty/error/forbidden states, capability-guarded row actions, confirmation dialog for disable/enable/session actions.
- `frontend/src/modules/platform/components/UserCreateDialog.vue` — new create dialog using `FormDialog`, client validation, Chinese server-field mapping, optional employment binding.
- `frontend/src/modules/platform/components/UserRoleDrawer.vue` — new role diff drawer using `FormDrawer`; explicit added/removed sets, risk copy, save through existing role APIs, conflict refresh.
- `frontend/src/modules/platform/components/UserPermissionPreview.vue` — read-only effective-permission preview grouped by Chinese module labels, with anchor/field-profile summaries and denied reasons.
- `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php` — list search/filter/pagination and display/employment/last-login projections; create with optional employment link; 409 last-admin protection; reject tenant/legal-entity request parameters.
- `frontend/tests/unit/systemUsersPage.spec.ts` — extended from 3 to 16 tests.
- `.superpowers/sdd/artifacts/v2/task-6-report.md` — this report.

`RoleAdminService.php` was listed in the brief but required no change for this slice; it is currently modified by the parallel Task 9 owner and was intentionally not staged here.

## TDD evidence
- RED: the extended suite initially failed 9/11 cases against the old page (missing display/binding/last-login columns, create dialog, role diff, grouped preview, confirmation flow, forbidden state).
- GREEN: after implementation and compatibility fixes, `frontend/tests/unit/systemUsersPage.spec.ts` passes all 16 tests.

## Acceptance coverage
1. **List** — username, display name, Chinese status, real effective role codes, employee binding, last login; search + status/role filters + page/page-size; loading, empty, error and forbidden states. No tenant/legal-entity column or vocabulary in the four workbench sources (source-scan test included).
2. **Create** — username, email, display name, initial password (min 12, matching existing auth policy), optional employment id; server 422 field errors map to Chinese field messages; dialog closes and list refetches on success.
3. **Role drawer** — current roles, assignable choices, explicit added/removed diff, reason required, risk warning; saves with existing `POST /v1/users/{id}/roles` and `DELETE /v1/users/{id}/roles/{roleCode}`; stable conflict reasons map to Chinese and trigger list refetch.
4. **Permission preview** — read-only, effective actions grouped by module, Chinese action labels, anchor and field-profile summaries, page and denied-reason lists; capability codes are not shown to the operator.
5. **Destructive actions** — disable/enable and revoke-session dialogs include the user's display name and a reason; last-admin failures are surfaced in Chinese. The UI recognises both `409 LAST_ACTIVE_ADMIN` and Task 9's `422 LAST_ENABLED_ADMIN_PROTECTED` so the guard can change without leaving raw errors.
6. **Shared UI/API** — uses `PageShell`, `DataTable`, `FormDialog`, `FormDrawer`, existing `resourceGet/resourcePost/resourcePatch/resourceDelete`, and Element Plus; no API client or generated-file edits.
7. **Capability guards** — create/manage/role actions are hidden without `iam.user.manage` / `iam.role.manage`; read-only users retain only preview. Backend routes were verified as already capability-guarded; no routes were added.
8. **Chinese copy** — all user-facing labels/errors are Chinese; raw `ACTIVE`/API paths/capability codes do not appear in the rendered workbench.

## Verification (fresh runs)
- `cd frontend && pnpm exec vitest run tests/unit/systemUsersPage.spec.ts`
  → **Tests 16 passed (16)**, 1 file passed.
- `cd frontend && pnpm typecheck` → passed (no output).
- `cd frontend && pnpm lint` → passed with `--max-warnings=0`.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never --filter='UserAdmin|RoleAdmin'`
  → **OK (4 tests, 34 assertions)**.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test app/Modules/IAM/Http/Controllers/UserAdminController.php`
  → **PASS (1 file)**.
- `phpstan analyse app/Modules/IAM/Http/Controllers/UserAdminController.php` → **OK, no errors**.
- Full `vendor/bin/pint --test` was run and failed only on `backend/routes/api.php` (`line_ending`); that file is owned by the controller/lead and was being normalized at integration. All Task 6-owned PHP files pass Pint.

## API-contract gaps needing the spec owner
1. `UserSummary` in `docs/openapi/openapi.yaml` does not declare `display_name`, `last_login_at`, or `employment_binding`; `UserCreated` does not declare `display_name`/`employment_id`.
2. `GET /v1/users` spec documents only `page`/`page_size`; the implemented and tested `q`, `status`, and `role_code` filters are not documented.
3. The repo has no admin target-user “revoke all sessions” operation. The workbench keeps the published audited `PATCH /v1/users/{id}` status command (current status + reason) as the session-revocation path; a dedicated operation/field should be added before treating it as a separately grantable action.
4. There is no writable `display_name` persistence field or People contract for an account without employment. Create accepts/validates/echoes it, and list uses the linked person's name when available; without an employment binding the value currently falls back to the username. A persisted display-name field (or a People profile write contract) is required for durable storage.
5. Task 6's brief mentioned an admin password-reset operation and helper fixtures, but the user's explicit file allowlist forbids routes/OpenAPI, and no backend test files were in that allowlist. No reset-password endpoint was added; it remains a contract gap.
