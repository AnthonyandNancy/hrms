# T19-MCP-011 — R04 payroll specialist (PAYROLL_RUN anchor)

- Date: 2026-11-28
- Backend image: `factoryhr/php-app:v24-t19j`
- Frontend image: `factoryhr/frontend:v24-t19i`
- Real stack: `factoryhr-t19`

## Result

- Created synthetic `t18.payroll` (id 12).
- Assigned standard `PAYROLL_SPECIALIST` template with PAYROLL_RUN=1: **201**.
- Run #1 membership added as REVIEWER: **201**.
- Password login: **200**.
- API:
  - `/v1/payroll/runs`: 200;
  - `/v1/payroll/runs/1`: 200;
  - `/v1/payroll/runs/1/employees`: 200;
  - `/v1/payroll/runs/1/inputs`: 200.
- SPA `/payroll` and `/payroll/runs/1`: HTTP 200, zero post-login non-2xx and
  zero console errors.

## Boundary

`PREPARER` is intentionally not assignable through the run-member API; the run
creator is stored as PREPARER. R04 was scoped with REVIEWER membership for
read/action inspection. The full prepare/calculate/approve action matrix and a
clean run-owned preparer flow remain in the role matrix backlog.
