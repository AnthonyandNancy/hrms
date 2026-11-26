# Volume 2 Task 7 — 角色授权工作台（前端）重做报告

**Status:** DONE (frontend-only slice) — supersedes the stopped first attempt.

## Why this is a retry
The first Task 7 attempt was time-boxed out before producing any committed file. This retry
started fresh from the existing `SystemRolesPage.vue` and the Volume 2 brief. No backend file,
OpenAPI document, generated API type, API module, router registry, or Task 6/8-owned file was
edited.

## Delivered files (explicit paths)
- `frontend/src/modules/platform/pages/SystemRolesPage.vue` — rebuilt workbench.
- `frontend/src/modules/platform/components/RolePageTree.vue` — new registry-driven tri-state page tree.
- `frontend/src/modules/platform/components/RoleActionMatrix.vue` — new action matrix from `actionsForPage()`.
- `frontend/src/modules/platform/components/RoleScopeEditor.vue` — new anchor/scope editor with org picker and CASE/PAYROLL_RUN hints.
- `frontend/src/modules/platform/components/RoleFieldPolicyEditor.vue` — new P0-P3 field profile editor with ceiling warnings and changed-row emissions.
- `frontend/tests/unit/systemRolesPage.spec.ts` — new, 8 focused Vitest cases.

## Acceptance coverage
1. Role list create / rename / copy / delete; template roles have disabled rename/delete and a Chinese
   built-in hint. All mutating controls are hidden without `iam.role.manage`.
2. `RolePageTree` is built from the read-only `PAGE_REGISTRY` menu metadata, groups by menu group,
   derives checked/partial/unchecked group state (real `indeterminate`), renders page code and path,
   and shows the Chinese ADR-0035 note that page view and action grants are independent.
3. `RoleActionMatrix` rows come only from `actionsForPage(pageCode)` and display capability,
   anchor, and field profile. Selecting an action also selects its page; deselecting a page with
   selected actions shows a Chinese confirm prompt that clears those actions on confirmation.
4. `RoleScopeEditor` renders only the seven allowed anchors — SELF / MANAGED / ORG_UNIT /
   ORG_SUBTREE / COMPANY / CASE / PAYROLL_RUN — even when the API fixture includes LEGAL_ENTITY.
   ORG_UNIT and ORG_SUBTREE show an org-unit picker; CASE and PAYROLL_RUN show explanatory hints.
5. `RoleFieldPolicyEditor` chooses P0_PUBLIC / P1_INTERNAL / P2_SENSITIVE / P3_HIGH_SENSITIVE per row,
   warns when the selection exceeds the capability ceiling, and the parent submits only changed rows.
6. One atomic save calls `PUT /v1/roles/{code}/authorization` with page, capability, scope, field and
   diff payload; the diff dialog shows added/removed counts before commit; save is disabled in flight;
   a 409 refetches roles + role pages and shows Chinese copy.
7. Loading / empty / error / forbidden states and Chinese copy are present; mutating controls are
   capability-guarded through `useSessionStore().can('iam.role.manage')`.

## Verification (real runs)
- `cd frontend && pnpm exec vitest run tests/unit/systemRolesPage.spec.ts`
  → **Tests 8 passed (8)**, 1 file passed.
- `cd frontend && pnpm typecheck`
  → passed, no output.
- `cd frontend && pnpm lint`
  → passed with `--max-warnings=0`.

## Deviations / concerns
- The brief's backend steps were explicitly out of scope for this retry (CONCURRENCY FACTS prohibit
  touching `RoleAdminService`, `RolePageController`, routes and OpenAPI). The frontend therefore calls
  the planned atomic endpoint `PUT /v1/roles/{id}/authorization`; until Task 7's backend half exists this
  endpoint will 404 in real API mode. Existing `PUT /v1/roles/{id}/pages` and `/capabilities` remain
  untouched and are not used for the atomic save.
- Rename uses `PATCH /v1/roles/{id}` and delete uses `DELETE /v1/roles/{id}`, neither of which exists in
  the current backend contract. Controls are implemented per acceptance bar and capability-guarded, but
  these calls need backend counterparts before production.
- The old `frontend/tests/unit/rolePermissionMatrix.spec.ts` still targets the previous page internals and
  now fails because the rebuilt page requires Pinia. It is not in Task 7's owned file list; the new
  `systemRolesPage.spec.ts` supersedes its coverage. Repo-wide Vitest is not claimed green.
- Field profile payloads use the Volume 2 task wording `P3_HIGH_SENSITIVE`; the current backend catalog
  still spells this `P3_RESTRICTED`. This must be reconciled by the backend work.
- No OrgUnit endpoint beyond the existing `/v1/org-units/tree` was used; its failure degrades to a visible
  unavailable hint rather than fake data.
