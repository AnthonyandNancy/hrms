# T19-MCP-007 — R06 line manager employee directory

- Date: 2026-11-28
- Frontend image: `factoryhr/frontend:v24-t19i`
- Backend image: `factoryhr/php-app:v24-t19h`
- Real stack: `factoryhr-t19`, session `demo.manager` (LINE_MANAGER, employment 1)

## Result

- `/v1/employees?page=1&page_size=20`: **200**, total **3**
  (manager + two direct reports; unmanaged DEMO-0004 excluded).
- `/hr/employees` rendered in the real SPA.
- `/v1` non-2xx after login: **0**.
- Console errors after login: **0**.
- The department/position selector requests are gated by `org.unit.read` and
  `org.position.read`; manager does not issue them.

## Change

- Employee directory routes no longer hardcode COMPANY; the controller allows
  COMPANY or MANAGED and constrains rows to direct reports.
- EmployeeListPage skips department/position lookups when the corresponding
  capability is absent.
- RoleMatrixTest now reflects the scoped MANAGED directory guard; the
  no-direct-report fixture still denies, and live manager behavior is captured
  here.

## Evidence

- `manager-directory.json`
- `manager-employees.png` / `.yml`
- `manager-employees-network.txt`
