# T19-MCP-008 — R09 auditor audit page after readiness repair

- Date: 2026-11-28
- Backend image: `factoryhr/php-app:v24-t19j`
- Real stack: `factoryhr-t19`, session `t18.auditor`
- Synthetic two-administrator environment is intentional for the role matrix.

## Repair

`HealthService::administratorHealthy()` previously required exactly one
privileged active user. Any normal second administrator therefore made
`/v1/health/ready` return 503. Readiness now requires **one or more** active
administrators; zero remains unhealthy. Regression covers both 0 and 2.

## Result

- `/v1/health/ready`: **200**, all checks true
  (`db`, `cache`, `storage`, `migrations`, `installation`, `administrator`).
- Auditor `/system/audit` page loaded with HTTP 200.
- `/v1/audit/events?page=1&page_size=20`: **200**.
- `/v1/reporting/dashboard`: **200**.
- Post-login `/v1` non-2xx: **0**; the only console error is the expected
  pre-login `/v1/me` 401.

## Evidence

- `auditor-audit.png` / `.yml`
- `auditor-audit-network.txt`
- `readiness-v24-t19j.json`
