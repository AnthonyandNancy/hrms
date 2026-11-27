# Volume 3 Task 2 — Employee List Workspace Report

## Status

**Implemented and committed.** Focused vitest, full frontend unit suite,
typecheck and lint are green. The real-stack Playwright spec is present and
lists two tests; execution is **NOT RUN** because no backend/frontend real
stack is reachable in this environment (see E2E section).

## Commit

`75caed9` — `feat(people): build the employee list workspace`

Staged paths only (explicit `git add -- <paths>`, never `git add -A`):

- `frontend/src/modules/people/employees/pages/EmployeeListPage.vue` (new)
- `frontend/src/modules/people/employees/components/EmployeeFilter.vue` (new)
- `frontend/src/modules/people/employees/components/EmployeeTable.vue` (new)
- `frontend/src/modules/people/employees/components/EmployeeCreateDrawer.vue` (new)
- `frontend/src/modules/people/employees/api.ts` (new)
- `frontend/src/modules/people/pages/EmployeeListPage.vue` (thin re-export)
- `frontend/tests/unit/EmployeeListPage.spec.ts` (new)
- `frontend/tests/e2e/real/employee-list.spec.ts` (new)
- `frontend/tests/unit/peoplePages.spec.ts` (retargeted — lead-authorized)
- `frontend/tests/unit/peoplePageModules.spec.ts` (retargeted — lead-authorized)

No router registry, detail page, backend, OpenAPI, `frontend/src/api/**`, or
other domain files were edited. The registry key stays `hr/employees/index` and
the existing module manifest keeps resolving it, so the 160-key page audit is
untouched. Task 3 owned and committed `fe9e023` before this commit; the combined
tree is green.

## What was built

1. **API boundary** (`employees/api.ts`)
   - `listEmployees` calls `GET /v1/employees` with `as_of`, `org_unit_id`,
     `q`, `page`, `page_size`, `include_archived`, and normalises the projection
     (employment_id, person name/number, status/type, hire date, archive flag,
     assignment ids/names) without inventing rows.
   - Missing `employment_id` is rejected rather than rendered as a fake row.
   - `partial`/`partial_reason` are preserved exactly as reported.
   - `listOrgUnits` (`GET /v1/departments`) and `listPositions`
     (`GET /v1/positions`) supply filter/drawer lookups.
   - `onboardEmployee` calls `POST /v1/employees/onboard` with the supplied
     idempotency key and normalises `{ person_id, employment_id, employee_no,
     assignment_id, terms_id, replayed }`.
   - `archiveEmployee`/`restoreEmployee` call the real archive/restore commands
     (reason as the validated DELETE query input, because the shared
     `resourceDelete` helper has no body; restore uses `Idempotency-Key`).

2. **Server-side list + filters** (`EmployeeListPage.vue`,
   `EmployeeFilter.vue`)
   - Search, as-of date, department and include-archived share a 250 ms
     debounce; keystrokes coalesce into one request.
   - Every filter change resets `page` to 1. Page/page-size changes keep
     filters and issue the next server page.
   - Stale responses are discarded with a request sequence guard; unmount
     invalidates in-flight loads.
   - Archived rows are opt-in but never silently hidden: the footer states
     that archived records are excluded and tells the operator how to reveal
     them.

3. **Table** (`EmployeeTable.vue`)
   - Columns exactly: 员工编号, 姓名, 部门, 岗位, 状态, 入职日期, 操作(查看).
   - Chinese status labels (在职/试用期/休假中/停职/已离职/未知状态) and Chinese
     employment types (全职/兼职/固定期限/合同制/实习/外包/顾问); unknown codes
     render Chinese fallbacks, never raw enums.
   - Archived rows carry a 已归档 badge and muted styling; they remain visible.
   - Every row has a real `<a href="/hr/employees/{employment_id}">查看</a>`
     and the whole row navigates to the same detail route.
   - 归档/恢复 actions are independently capability-gated.

4. **Five states**
   - loading: skeleton rows (`data-testid="employee-table-loading"`).
   - empty: 暂无员工 with a 新建员工 CTA when `people.employee.manage` is held
     (otherwise a 清空筛选 action).
   - error: shared `ErrorState`, Chinese copy, request id, retry.
   - forbidden: dedicated 403 panel with Chinese message and request id, no
     rows rendered and no raw code.
   - partial: warning banner carrying the server's `partial_reason`.

5. **Create drawer** (`EmployeeCreateDrawer.vue` via shared `FormDrawer`)
   - Groups: 主档案 / 入职信息 / 岗位任职 / 雇佣条款.
   - One idempotency key is generated per logical submission payload and reused
     across retries of the same failed submission; it is replaced only after
     success or when the payload changes.
   - In-flight submit is disabled by `FormDrawer`'s `confirmLoading`.
   - 422 field errors map onto `aria-invalid` inputs with inline Chinese copy
     (including nested `person.*`, `employment.*`, `assignment.*`, `terms.*`
     keys); unknown fields fall back to a Chinese summary.
   - 409 `EMPLOYEE_NO_DUPLICATE` renders `员工编号 <no> 已存在...` in Chinese and
     never exposes the raw code/reason.
   - Success closes the drawer, emits the created result, the page shows
     `员工 <employee_no> 已创建。` (or an explicit idempotent-replay message),
     resets to page 1 and refetches the list.

6. **Capability guards**
   - 新建员工 is hidden without `people.employee.manage`; empty-state CTA follows
     the same guard.
   - 归档 requires `people.employee.archive`; 恢复 requires
     `people.employee.restore`. Nothing the API would allow is hidden.

## Shared-spec retargets (lead-authorized; retargeted, not deleted)

The Volume 3 page model contradicts two legacy assertions. Lead explicitly
authorized the following, with the rule "retarget, don't delete":

- `frontend/tests/unit/peoplePages.spec.ts`
  - Employee list now asserts the Chinese `在职` label (and absence of raw
    `ACTIVE`) while keeping the same row/API coverage.
  - Added archived-row coverage (row remains rendered and is marked 已归档).
  - The dedicated detail page's 409 assertion retargets the old
    `no-active-employment` test id to the shared `.error-state` while keeping
    the exact Chinese copy and request-id assertions.
- `frontend/tests/unit/peoplePageModules.spec.ts`
  - `EmployeeListPage.vue` and `EmployeeDetailPage.vue` are excluded from the
    generic `<PeopleWorkspace :config>` assertion and asserted to be thin
    re-exports/wrappers with no `PeopleWorkspace`/`peoplePageConfig`.
  - The employee list case asserts the dedicated workspace renders (no
    PeopleWorkspace) and clarifies the expected `/v1/departments` +
    `/v1/positions` lookups.
  - Every other People page keeps its original generic-shell assertion, and the
    manifest/router assertions are unchanged.

No tests were deleted or weakened outside these structural retargets.

## TDD evidence

The vitest suite was written first and watched fail for the right reason
(`Failed to resolve import "@/modules/people/employees/pages/EmployeeListPage.vue"`).
The 12 tests cover:

1. loading skeleton + Chinese status/type + archived badge + detail href +
   row-click navigation + list query shape.
2. filter debounce/coalescing + page resets to 1 for search and archived.
3. empty state CTA opens the create drawer.
4. error state with request id, no raw message, retry recovers.
5. forbidden state with no rows/raw codes.
6. partial banner.
7. archive/restore capability guards.
8. create success (payload, idempotency key, close, refetch, employee_no).
9. 422 field mapping + `aria-invalid`, no raw code.
10. stable idempotency key across retry + in-flight disable.
11. 409 duplicate employee number in Chinese.
12. (plus the archived-row/Chinese-label assertions are also covered in
    `peoplePages.spec.ts` as described above).

## Verification (real output)

- `cd frontend && pnpm exec vitest run tests/unit/EmployeeListPage.spec.ts`
  → **1 file / 12 tests passed**.
- `cd frontend && pnpm typecheck` → **PASS**.
- `cd frontend && pnpm lint` → **PASS** (`--max-warnings=0`).
- `cd frontend && pnpm test:unit`
  → **54 files / 392 tests passed**.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit
  --colors=never`
  → **OK (1196 tests, 57763 assertions)** (sanity check; no backend edits).
- `cd frontend && pnpm exec playwright test --config=playwright.real.config.ts
  --list tests/e2e/real/employee-list.spec.ts`
  → **Total: 2 tests in 1 file**.

## E2E

Spec: `frontend/tests/e2e/real/employee-list.spec.ts`

- Uses `playwright.real.config.ts`.
- Skips with an explicit reason unless `HRMS_DEMO_PASSWORD` or
  `DEMO_SEED_PASSWORD` is set.
- Cookie login as `demo.hr` through the real login form.
- Asserts the list renders, that the search filter narrows rows to
  `DEMO-0002`, that the 查看 link targets `/hr/employees/{id}`, and that the
  create drawer opens with the expected fields and enabled 保存.

**NOT RUN** against a live stack: Docker containers for this worktree are up,
but no app port is published/reachable (`127.0.0.1:8080`, `:8000` both refused),
and the seeded password env vars are not present in this session. Only
`--list` was executed, as required by the task's verify block.

## Deviations / notes

- No server-side `sort` query parameter exists in the live contract
  (`GET /v1/employees` validates only as_of, org_unit_id, q, page, page_size,
  include_archived; the query orders by `employments.id`). "Server-side sort"
  is therefore satisfied by the server's canonical stable ordering; adding a
  client-invented sort param would silently violate the OpenAPI contract and
  would require backend/OpenAPI edits outside this task. Flagged for a future
  contract task rather than faked.
- The archive reason travels as `?reason=` because the shared
  `resourceDelete` helper sends no body. The backend validates `reason` on the
  same DELETE operation, so this is the same command and validation path.
- Department/position names are resolved from the as-of lookup reads; when a
  lookup fails the table shows a clearly labelled `组织 #id` / `岗位 #id`
  instead of inventing names.
- Task 3's detail page was committed as `fe9e023` immediately before this
  commit; this commit does not stage any Task 3 file.

## Files

| Path | Purpose |
| --- | --- |
| `frontend/src/modules/people/employees/api.ts` | List/lookups/onboard/archive/restore boundary |
| `frontend/src/modules/people/employees/components/EmployeeFilter.vue` | Debounced server-side filters |
| `frontend/src/modules/people/employees/components/EmployeeTable.vue` | Chinese table, archived markers, pagination, actions |
| `frontend/src/modules/people/employees/components/EmployeeCreateDrawer.vue` | Onboard drawer with idempotency + 422/409 mapping |
| `frontend/src/modules/people/employees/pages/EmployeeListPage.vue` | Five states, filters, create, row actions |
| `frontend/src/modules/people/pages/EmployeeListPage.vue` | Thin compatibility re-export |
| `frontend/tests/unit/EmployeeListPage.spec.ts` | 12 focused tests |
| `frontend/tests/e2e/real/employee-list.spec.ts` | 2 real-stack tests |
| `frontend/tests/unit/peoplePages.spec.ts` | Retargeted legacy list/detail assertions |
| `frontend/tests/unit/peoplePageModules.spec.ts` | Dedicated-page exemptions + thin re-export assertions |
