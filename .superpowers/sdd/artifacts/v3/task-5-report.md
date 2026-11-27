# Volume 3 Task 5 — Department Workspace Report

## Status

**Implemented and committed. Integration remains blocked by missing backend
department create/edit/remove/manager routes.**

The department workspace frontend is implemented, TDD-covered, and committed
with only the six Task 5 paths. The page consumes the existing reads
(`GET /v1/departments`, `GET /v1/org-units/tree`,
`GET /v1/departments/{id}/members`) and the existing writes
(`POST /v1/departments/{id}/members`, `POST /v1/org-units/{id}/move`).

Per the task brief, create/edit call `POST /v1/departments` and
`PATCH /v1/departments/{id}`. The live route table in this worktree does **not**
contain those routes yet; no local fake create/edit is performed, so those
flows surface the backend 404/405 failure inline in the drawer.

The page is not router-wired because `frontend/src/router/registry.ts` is an
explicitly forbidden file for this task. The existing `/hr/departments` route
still resolves the legacy `hr/departments/index` page.

## Commit

`34cc4bc` — `feat(org): build the department workspace`

Staged paths only:

- `frontend/src/modules/people/departments/pages/DepartmentWorkspacePage.vue`
- `frontend/src/modules/people/departments/components/DepartmentTree.vue`
- `frontend/src/modules/people/departments/components/DepartmentFormDrawer.vue`
- `frontend/src/modules/people/departments/components/DepartmentMembersPanel.vue`
- `frontend/src/modules/people/departments/api.ts`
- `frontend/tests/unit/DepartmentWorkspace.spec.ts`

No backend, OpenAPI, router, or other page file was edited.

## What was built

1. **Organisation tree** (`DepartmentTree.vue`)
   - Lazy expand/collapse: roots are rendered first; children only render after
     the parent toggle is pressed.
   - Search over the whole flattened tree by name **or** code, independent of
     expansion state.
   - Every node displays its headcount (`在编 N 人`); a missing department
     projection renders `在编 —` instead of an invented zero.
   - Tree rows merge `GET /v1/org-units/tree` structure with
     `GET /v1/departments` headcount and limit data; no synthetic rows.

2. **Department detail** (inline in `DepartmentWorkspacePage.vue` because the
   area only receives four component files)
   - Chinese labels for all six types (`COMPANY` 公司, `DIVISION` 事业部,
     `DEPARTMENT` 部门, `TEAM` 团队, `COST_CENTER` 成本中心,
     `SHARED_SERVICE` 共享服务) and statuses (启用/停用).
   - Headcount, headcount limit, member count, effective range and parent.
   - Manager row explicitly shows `—（接口未返回）` because the current
     `Department` projection has no manager field; it never invents a name.

3. **Create/edit drawer** (`DepartmentFormDrawer.vue`)
   - Name, code, parent, type, manager employment id, effective from/to.
   - Half-open range validated client-side: end must be strictly later than
     start, with inline Chinese explanation of `[生效日, 失效日)`.
   - Server 422 field errors are mapped inline per field (`name`, `code`,
     `parent_id`, `type`, `valid_from`, `valid_to`,
     `manager_employment_id`) and the drawer stays open.
   - Sends `POST /v1/departments` / `PATCH /v1/departments/{id}` with an
     idempotency key.

4. **Move with cycle prevention**
   - Parent picker removes the moving department and every descendant, both for
     the edit drawer and the move dialog.
   - Explicit two-step confirmation names both departments and the effective
     date; a descendant/self target is refused before submission.
   - Submission uses the existing `POST /v1/org-units/{id}/move` with
     `new_parent_id` and `effective_from`.
   - The server-side 422 from a bypassed/racy descendant target is rendered
     inline.

5. **Members panel** (`DepartmentMembersPanel.vue`)
   - Lists real member assignments with Chinese assignment types (主任职,
     借调, 矩阵任职, 项目任职) and effective dates.
   - Add uses `POST /v1/departments/{id}/members` with employment id, effective
     date and a reason of at least 3 characters; refetches the real projection.
   - Remove always asks for confirmation first. After confirmation the panel
     renders an explicit blocker naming the missing effective-dated removal
     command; no DELETE/POST request is invented.

6. **Five states and capability guard**
   - Loading, empty, error (with retry/request id), forbidden, and partial
     (department projection or tree failure keeps the other side visible).
   - Mutating controls are guarded by `org.unit.manage` from the session store;
     read-only callers keep the tree/detail but see no create/edit/move/member
     controls.
   - Chinese enums and no tenant/legal-entity controls anywhere.

## TDD evidence

- `frontend/tests/unit/DepartmentWorkspace.spec.ts` was written first (9 tests).
- Observed RED: Vitest failed to resolve the not-yet-created
  `@/modules/people/departments/pages/DepartmentWorkspacePage.vue`.
- Implemented the production files and iterated to green.

Coverage:

1. lazy tree expansion plus per-node headcount;
2. whole-tree search by code/name with preserved headcount;
3. detail panel Chinese labels, headcount, member count and manager gap;
4. half-open date validation before transport + server 422 inline;
5. edit drawer excludes self/descendants as a parent;
6. move parent picker excludes descendants and `canUseAsParent` unit checks;
7. move confirmation names both departments and posts only at final confirm;
8. member add payload/refetch + removal confirmation and explicit endpoint
   blocker;
9. forbidden + read-only capability guards;
10. loading, empty, partial and error states (bundled into the state test).

## Verification

- `cd frontend && pnpm exec vitest run tests/unit/DepartmentWorkspace.spec.ts`
  → **9 passed / 9**.
- `cd frontend && pnpm exec eslint src/modules/people/departments tests/unit/DepartmentWorkspace.spec.ts --max-warnings=0`
  → **PASS (exit 0)**.
- `cd frontend && pnpm typecheck`
  → **no errors in task files**. The full command is red only because
  concurrent Task 2/Task 9 work has uncommitted/broken files
  (`src/modules/time/attendance/api.ts`, missing
  `src/modules/people/employees/pages/EmployeeDetailPage.vue` referenced by a
  concurrently-created unit spec).
- `cd frontend && pnpm lint`
  → **no errors in task files**; the full command is red only on
  `src/modules/time/attendance/api.ts` (concurrent Task 9 edit).
- `cd frontend && pnpm test:unit`
  → **51 files passed, 2 failed / 53; 363 tests passed, 4 failed / 367**.
  All four failures are outside Task 5:
  - `tests/unit/EmployeeDetailPage.spec.ts` fails to resolve the not-yet-created
    concurrent Task 3 page;
  - four assertions in `tests/unit/AttendanceWorkspace.spec.ts` fail against
    the concurrent Task 9 API rewrite.

Backend tests were not run: no backend file was changed.

## Missing / mismatched backend endpoints

1. **`POST /v1/departments`** — required for create; the live router exposes
   only `POST /v1/org-units` for creation.
2. **`PATCH /v1/departments/{id}`** — required for edit; no department update
   route exists in the live router.
3. **Effective-dated member removal** — no `DELETE`/`PATCH` command exists to
   end a member assignment as of `effective_from`. Current API only supports
   `POST /v1/departments/{id}/members` (move a primary assignment in).
4. **Department manager contract** — `Department` has no
   `manager_employment_id` / `manager_name` in the live projection and no write
   field on create/update. The UI surfaces this gap and sends the field in the
   create/edit payload so the backend can validate it once implemented.
5. **Router wiring** — `frontend/src/router/registry.ts` is forbidden for this
   task, so `/hr/departments` is not switched to `DepartmentWorkspacePage` yet.
   The integration owner must point `hr/departments/index` at the new page.
