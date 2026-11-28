# T19-ROLES-001 — synthetic role accounts

- Date: 2026-11-28
- Real stack: `factoryhr-t19`
- Synthetic only; no real personal data.

## Accounts

- demo.hr: HR_ADMIN + EMPLOYEE, employment 1
- demo.manager: LINE_MANAGER + EMPLOYEE, employment 1
- demo.employee: EMPLOYEE, employment 2
- demo.admin: SYSTEM_ADMIN + EMPLOYEE, employment 1
- t18.recruiter: RECRUITER, ORG_UNIT=4/CASE=2 (T19-MCP-006)
- t18.auditor: AUDITOR, created and assigned in this run

All five demo/auditor password logins returned 200 after the transient route
throttle window. The route-level `throttle:5,1` was observed and documented;
login attempts are not role failures.

## Role-scoped observations

- R02 demo.hr: employees/departments/reports pages 200 with no console errors.
- R07 demo.employee: workspace/leave pages 200; payroll page denied as expected.
- R06 demo.manager: workspace 200; employee directory fixed in T19-MCP-007.
- R09 t18.auditor: account and role assignment 201; audit/report/user pages are
  subject to GAP-24 below.
- Readiness `/v1/health/ready` is **503** only because the synthetic T18 role
  seed creates a second privileged admin; readiness requires exactly one
  administrator. This is a documented synthetic-matrix environment condition,
  not a production baseline claim.

## Not accepted

This pack provisions and tests account access only. It is not a full R01–R09
module/action matrix.
