# T19-CRAWL-002 — no-employment time pages after GAP-21 fix

- Date: 2026-11-28
- Frontend image: `factoryhr/frontend:v24-t19e`
- Backend image: `factoryhr/php-app:v24-t19e`
- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Session: synthetic installer (SYSTEM_ADMIN, no employment)
- Health: 200, db/cache/storage=true.

## Fix scope

- `/v1/time/attendance/devices` now accepts the documented
  `active=true|false` query form (previously 422).
- Attendance, leave, overtime and travel self-scoped requests are gated by
  `principal.employmentId`; the pages render `NoActiveEmploymentState` instead
  of issuing self-service requests.
- Management tabs remain available.

## Observed after deployment

For each of `/attendance`, `/leave`, `/overtime`, `/travel`:

- page title/render succeeded;
- `当前账号未绑定员工` state visible;
- all `/v1` responses were 2xx;
- console errors = 0.

Screenshots: `attendance.png`, `leave.png`, `overtime.png`, `travel.png`.
