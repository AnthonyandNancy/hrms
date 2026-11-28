# T19-MCP-010 — R03 attendance specialist and R05 payroll approver

- Date: 2026-11-28
- Backend image: `factoryhr/php-app:v24-t19j`
- Frontend image: `factoryhr/frontend:v24-t19i`
- Real stack: `factoryhr-t19`

## R03 — ATTENDANCE_SPECIALIST

- Created synthetic `t18.attendance` (id 10).
- Assigned role with ORG_UNIT=4: **201**.
- Password login: **200**.
- Real SPA `/attendance?tab=punches&month=2026-11&employee=2`:
  - devices **200**;
  - scoped attendance records **200**;
  - only the expected pre-login `/v1/me` 401 console entry.

## R05 — PAYROLL_APPROVER

- Created synthetic `t18.approver` (id 11).
- First role assignment correctly refused with SoD risk; retry with explicit
  `acknowledge_sod_risk=true`: **201**.
- Added run #1 membership as APPROVER: **201**.
- Password login: **200**.
- Real `/payroll/runs/1`: **200**;
  - employees/reconciliation/inputs all **200**;
  - only the expected pre-login `/v1/me` 401 console entry.

## Scope

This proves role construction and read-path access for R03/R05. It does not
claim every create/approve/correct action, all ten roles' full module matrix,
155 legacy entries or 458 cards.
