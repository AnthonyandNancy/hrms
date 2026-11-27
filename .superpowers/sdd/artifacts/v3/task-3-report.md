# Volume 3 Task 3 — Employee Detail Workspace Report

## Status

**Implemented, tested, verified, and committed.**

The employee detail page is a same-object, same-task, same-scope workspace
(ADR-0035). It renders the summary header, lazily loads one tab at a time from
its own endpoint, isolates per-tab loading/empty/error/forbidden states, keeps
field-projection honesty (`无权查看`), and exposes archive/restore only behind
their capabilities and named confirmations.

## Commit

`fe9e023` — `feat(people): build the employee detail workspace`

Explicitly staged paths only:

- `frontend/src/modules/people/employees/pages/EmployeeDetailPage.vue`
- `frontend/src/modules/people/employees/components/EmployeeSummary.vue`
- `frontend/src/modules/people/employees/components/EmployeeTabs.vue`
- `frontend/src/modules/people/employees/detailApi.ts`
- `frontend/src/modules/people/pages/EmployeeDetailPage.vue`
- `frontend/tests/unit/EmployeeDetailPage.spec.ts`
- `frontend/tests/e2e/real/employee-detail.spec.ts`

No backend, OpenAPI, generated client, list-page, or `frontend/src/api/**` file
was changed. `frontend/src/router/registry.ts` was **not** changed (deviation
below).

## What was built

### Summary (`EmployeeSummary.vue`)

- Required fields: 姓名, 员工编号, 部门, 岗位, 状态, 入职日期.
- Also renders 用工类型, 工作地点, 工作安排 and 联系邮箱 when projected.
- Status and employment type use Chinese dictionaries; raw enum codes are
  never rendered.
- `hasOwnProperty` checks distinguish an omitted/hidden projection from an
  empty value: omitted and masked fields render `无权查看`, not `—` and never
  an inferred value. Department/position identifiers are shown only as the
  explicit fallback labels `部门 #10` / `岗位 #4`; no name is guessed.
- Archived employees get a banner (with archive reason when returned) and the
  summary suppresses 编辑/归档, offering only 恢复.

### Actions

- 编辑: guarded by `people.employee.manage`; navigates to the 变更申请 tab
  because core employee maintenance is an approval-bound change request in
  the current API (no client-side overwrite endpoint exists).
- 归档: guarded by `people.employee.archive`; `ElMessageBox.prompt` requires a
  reason of at least 3 characters and names the employee by 姓名 + 工号.
  Sends `DELETE /v1/employees/{employment_id}` with a JSON `{reason}` body,
  `Idempotency-Key` and the session CSRF header, then refetches the server
  projection.
- 恢复: guarded by `people.employee.restore`; `ElMessageBox.confirm` names the
  employee, then sends `POST /v1/employees/{employment_id}/restore`.
- Capability controls are hidden, not disabled, when missing.

### Tabs (`EmployeeTabs.vue`)

Allowed tabs only:

1. 基本信息 (`overview`, no network request)
2. 任职与异动 (`employment` → `GET /v1/employments/{id}/timeline`)
3. 合同 (`contracts` → `GET /v1/employments/{id}/contracts`)
4. 证照与附件 (`credentials` → certificates + document-files)
5. 教育经历 (`education` → `GET /v1/employees/{id}/education`)
6. 工作经历 (`experience` → `GET /v1/employees/{id}/experiences`)
7. 联系方式 (`contacts` → `GET /v1/employees/{id}/contacts`)
8. 变更申请 (`changes` → `GET /v1/employees/{id}/change_requests`)

No payroll, attendance or performance tabs. Every row comes from a server
response; empty sections show an honest `暂无…记录` state. Each tab keeps its
own state object, so one failed tab renders its own error/forbidden panel while
the summary and other tabs remain visible. The credentials tab fails as a unit
if either of its two subordinate endpoints fails; it never converts a failure
into a fake empty list.

### Route/deep links

- Reads `employment_id` from route params, with legacy `:id` fallback.
- `?tab=` accepts the allowed keys plus legacy aliases (`files`,
  `work-history`); invalid values fall back to overview without error.
- Changing tabs deep-links via `router.replace(..., { tab })`.
- 返回员工列表 pushes `/hr/employees` while preserving all non-`tab` query
  parameters (filters).
- API status is authoritative: 404 renders the Chinese “员工不存在或不在你的
  数据范围内” page; 403 renders 无权查看 and deliberately does not print the
  request id or employee identity (no existence leak). Other statuses render
  `ErrorState` with request id and retry.

## TDD evidence

- Wrote the vitest suite first; observed RED:
  `Failed to resolve import "@/modules/people/employees/pages/EmployeeDetailPage.vue"`.
- Implemented the minimum production files, then iterated:
  summary date formatting, then a true unit failure for a failed tab.
- Final focused run: **12 tests passed / 12** (8–12 requested).

Tests cover:

1. required summary fields with Chinese status;
2. capability-guarded edit/archive/restore;
3. archive confirmation names employee and calls command;
4. cancelled archive sends nothing;
5. archived banner + restore-only action with named confirmation;
6. lazy per-tab loading and no reload on overview;
7. Chinese enum labels and no raw codes in tab rows;
8. one failing tab does not blank summary/other tabs;
9. forbidden tab state keeps page usable;
10. omitted/masked fields render 无权查看, not inferred values;
11. API-driven 404 and 403 states without record leakage;
12. invalid `?tab=` fallback and back navigation with filters.

## Verification (real output)

All commands run from `frontend/` after the Task 2 commit was merged into the
same worktree:

- `pnpm exec vitest run tests/unit/EmployeeDetailPage.spec.ts`
  → **1 file passed, 12 tests passed / 12**.
- `pnpm typecheck`
  → **PASS** (`vue-tsc -b`, no output beyond script banner).
- `pnpm lint`
  → **PASS** (`eslint . --max-warnings=0`, exited 0).
- `pnpm test:unit`
  → **54 test files passed, 392 tests passed / 392**.
- `pnpm exec playwright test --config=playwright.real.config.ts --list tests/e2e/real/employee-detail.spec.ts`
  → **Total: 1 test in 1 file**.
- `node scripts/audit_frontend_modules.mjs` (repo root)
  → `{"ok":true,"scanned":212,...,"failures":[]}`.

### Playwright execution

**NOT RUN** — the explicit verification gate was `--list` (1 test listed). The
Docker engine is available, but neither the real backend (`127.0.0.1:8080`) nor
the real frontend (`127.0.0.1:5174`) was running, and no demo password was
available in this session. The spec supports both `HRMS_DEMO_PASSWORD` and
`DEMO_SEED_PASSWORD`, uses the real config + cookie login, opens
`/hr/employees/{id}?tab=employment`, asserts the summary/heading/tab shell, and
switches to 合同 and 教育经历 while asserting no cross-domain tabs.

## Deviations / decisions

1. **Registry componentKey unchanged.** The brief proposed changing
   `employee-detail` to `componentKey: 'people/employees/detail'` and deleting
   legacy detail registrations. The router resolves only through
   `modules/people/pages/index.ts` + `pageComponents.ts`; changing the key
   would fall back to `ResourcePage` and break the 160-key module/component
   counts. Lead explicitly decided to keep key `hr/employees/by-id`.
2. **Legacy wrapper authorized.** Lead authorized
   `frontend/src/modules/people/pages/EmployeeDetailPage.vue` to become a thin
   import + `<EmployeeDetailPage />` wrapper (same pattern as Task 2's list
   page). No other legacy page or page manifest was edited.
3. **Legacy sub-page registrations retained.** `/hr/employees/:id/education`,
   `/work-history`, `/contacts`, `/credentials`, `/history`, `/files`,
   `/changes` registry entries remain untouched because this task lacks
   ownership of registry edits. Their behavior is unchanged; the new detail
   workspace is reachable at `/hr/employees/:id` with all sections as tabs.
4. **编辑** opens the 变更申请 tab rather than a nonexistent direct employee
   PATCH flow; this avoids fabricating an update API or mutating the read model
   client-side.
5. Department/position names are not invented. The backend detail projection
   returns assignment IDs; the page shows explicit `部门 #id` / `岗位 #id`
   fallback labels unless the server includes a name. Task 2 resolves names
   for the list; doing the same here was outside this task's API/file scope.
6. Full unit suite was red (4 attendance tests) transiently after another
   agent's `90af76d` retargeted the attendance API but before their `dc6dd28`
   fix. On the final merged tree it is green at 392/392.
