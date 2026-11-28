# T19-MCP-002 — role copy and CASE/PAYROLL_RUN anchor inputs

- Date: 2026-11-28
- Frontend build: `aeccadc`
- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Browser: Playwright MCP Chromium

## Executed steps

1. Opened `/system/roles` with the real superadmin session.
2. Used the real 复制 action on built-in `PAYROLL_SPECIALIST`.
3. Submitted role code `PAYROLL_SPECIALIST_T19` and name `薪酬专员验收角色`;
   the new custom role appeared in the role list.
4. Opened the custom role and switched to 数据范围.
5. Observed `指定工资批次` selected for payroll-scoped capabilities and real
   `工资批次编号` number inputs (12 rows in the captured state). This is the new
   anchor-id surface that was missing before the fix.
6. Queried MySQL and confirmed `PAYROLL_SPECIALIST_T19` persisted with
   `is_template=0`.

## Evidence

- `system-roles-scopes.png`
- `system-roles-scopes.yml`
- `role-persisted.txt`

## Scope

- `PASS`: real admin can copy a role and receives numeric CASE/PAYROLL_RUN
  anchor-id controls.
- `NOT RUN`: saving a PAYROLL_RUN binding with a real run id, assigning the role
  to a user, and verifying run-scoped deny/allow. Those require the next MCP
  scenario to create a real payroll run first.
- No credential, cookie, CSRF token or real personal data is included.
