# Route crawl results

All listed routes returned HTTP 200 under the real SYSTEM_ADMIN session.

| Route | Result |
|---|---|
| /dashboard | PASS page render |
| /reports | PASS page render |
| /hr/employees | PASS page render |
| /hr/departments | PASS page render |
| /hr/positions | PASS page render |
| /hr/movements | PASS page render |
| /hr/contracts | PASS page render |
| /attendance | PARTIAL: console 422, no-employment context |
| /leave | PARTIAL: console 409, no-employment context |
| /overtime | PARTIAL: five console 409s, no-employment context |
| /travel | PARTIAL: console 409, no-employment context |
| /payroll | PASS page render |
| /payroll/runs/1 | PASS page render |
| /performance | PASS page render |
| /recruitment/requests | PASS page render |
| /recruitment/candidates | PASS page render |
| /training | PASS page render |
| /notices | PASS page render |
| /account | PASS page render |
| /system/users | PASS page render |
| /system/roles | PASS page render |
| /system/pages | PASS page render |
| /workflow | PASS page render |
| /benefits | PASS page render |
| /system/settings | PASS page render |
| /notifications | PASS page render |
| /system/audit | PASS page render |
| /workspace | PASS page render + no-employment state |

Skipped: six dynamic detail routes plus `/login`, `/forbidden`, `/not-found`.
