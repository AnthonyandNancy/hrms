# T19-MCP-004 — user role assignment UI and overlapping-role repair

- Date: 2026-11-28
- Frontend build: `aeccadc`
- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Synthetic user: `t19.payroll`

## Executed

1. Opened `/system/users` as the real installer administrator.
2. Opened `分配角色 · t19.payroll` and selected `AUDITOR`.
3. Entered a synthetic 3+ character reason and saved.
4. The user row showed `AUDITOR、PAYROLL_SPECIALIST_T19`.
5. Queried the effective role projection at the user's current
   `auth_version`: exactly these two role codes are effective. Historical
   `user_roles` rows are immutable snapshot generations copied by
   `AuthorizationSnapshot::replay`, not duplicate active assignments.

## Defect found and repaired

The assignment probe then attempted to add `TRAINING_ADMIN`, whose COMPANY
capabilities overlap AUDITOR. The pre-fix API returned HTTP 500 with a unique
constraint violation on `authority_bindings`.

- Added a failing regression in `UserRoleAssignmentApiTest`.
- `assignRole` now locks and reuses an existing binding for the same
  principal/capability/anchor/effective-window/version, widening only the field
  profile. It never inserts a second binding for that unique key.
- Full gate passed: 22 gates, 248.3s
  (`docs/implementation/v24/18-mcp-acceptance/gate-all-role-merge.log`).
- Real API retry after the runtime repair: **201**.
- Effective `auth_version=6` bindings: `org.tree.read` COMPANY = 1 row and
  `people.employee.read` COMPANY = 1 row.

## Evidence

- `user-roles-assigned.png` / `.yml`
- `effective-user-roles.txt`
- `overlap-before-fix.txt`
- `overlap-after-fix-response.json`
- `overlap-after-fix-effective-bindings.txt`
- `role-assigned-events.txt`

## Scope

- `PASS`: real user-management role assignment UI, persisted effective roles,
  overlapping-role API repair.
- `NOT RUN`: role removal through the same UI, permission-preview deep checks,
  remaining MCP matrix entries.
