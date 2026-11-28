# T19-MCP-003 — PAYROLL_RUN scoped role allow/deny

- Date: 2026-11-28
- Frontend build: `aeccadc`
- Backend: `d79a2a7` authorizer hot-patched into the `v24-t19b` runtime for this
  validation; final image build remains a T19 release step.
- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Synthetic fixtures:
  - scheme `T19_SCHEME`;
  - run #1 `2026-01`, run #2 `2026-02`;
  - user `t19.payroll` with no real personal data;
  - custom role `PAYROLL_SPECIALIST_T19`.

## Executed

1. Created the scheme and two DRAFT runs through the real API as setup data.
2. Created synthetic user `t19.payroll`.
3. In the real `/system/roles` UI, copied the Payroll specialist role and saved a
   `PAYROLL_RUN` anchor id. The UI showed `工资批次编号`, accepted run id #1 and
   the role-capability table persisted `payroll.run.read / PAYROLL_RUN / 1`.
4. Assigned the custom role to `t19.payroll` with binding `PAYROLL_RUN=1`.
5. Reproduced and fixed a real system-admin defect: the installer's
   `COMPANY` wildcard was denied on the `PAYROLL_RUN`-guarded member route. After
   the narrow authorizer fix and real member API retry, adding reviewer user 2 to
   run #1 returned **201**.
6. Logged in as `t19.payroll` and opened the real SPA:
   - `/payroll/runs/1` loaded the 2026-01 run detail (0 employees, DRAFT,
     amount fields hidden by field policy);
   - `/payroll/runs/2` rendered the real Chinese forbidden state:
     “无权读取该工资批次。当前账号必须持有 payroll.run.read 且属于该批次成员。”
7. API parity for the same session: run #1 = **200**, run #2 = **403**.

## Evidence

- `payroll-run-1-allowed.png` / `.yml`
- `payroll-run-2-forbidden.png` / `.yml`
- `member-api-response.json` (201)
- `run-1.json` (200), `run-2.json` (403)
- `role-capability.txt`, `user.txt`, `authority-bindings.txt`
- `run-status-summary.txt`

## Scope

- `PASS`: real UI role anchor entry/save, real run-scoped allow, real cross-run
  deny, real member API after wildcard fix.
- `NOT RUN`: role assignment through the user-management UI (assignment was
  synthetic setup), all other MCP matrix entries, real-release image build of
  the hot-patched backend, and production traffic recovery.
