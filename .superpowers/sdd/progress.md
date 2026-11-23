# HRMS V1.0 — Subagent-Driven Progress Ledger

Plan source: docs/HRMS_V1.0_实施计划_30周路线图.md (approved v1.1)
Solution source: docs/HRMS_V1.0_解决方案_第一性原理重构.md (approved v1.1)
Baseline commit: 227ddee (document baseline)

## Environment facts (verified)

- PHP 8.4.28 portable at `.tools/php/php.exe` (bcmath, pdo_sqlite, pdo_mysql, intl, mbstring, openssl, curl, zip enabled).
- Composer 2.11.2 at `.tools/composer2.phar`; `COMPOSER_HOME=.tools/composer-home`.
- Node 24.19.0; pnpm 10.34.5.
- Laravel 13.17 skeleton installed at `backend/`, `php artisan test` green.
- MySQL not installed locally; Docker engine is running but daemon previously unavailable.
- All three source documents plus `docs/README.md` and `docs/appendix_e.md` are committed and treated as authoritative requirements.

## Execution boundary

This is a **reference implementation of the documented architecture and P0 vertical slices**, not a 30-week production rollout. Real IdP credentials, production payroll data, HA infrastructure, penetration testing, and named business signatories are organizational gates that cannot be fabricated in code.

## Delivery tasks

- Task 1: Engineering baseline + platform (health, response envelope, request-id, errors, audit, outbox, idempotency, module boundaries, OpenAPI, CI).
- Task 2: IAM + capability×anchor authorization + sessions/MFA/break-glass.
- Task 3: Organization + Person/Employment/Assignment + as-of queries + terms/contracts.
- Task 4: Case/Decision/Effect + workflow + idempotent effects + outbox.
- Task 5: Lifecycle (onboarding, transfer, offboarding, contract renewal) + Talent thin slices.
- Task 6: Time/attendance/business calendar/leave ledger + reconciliation.
- Task 7: Payroll gross-to-net, cumulative withholding, snapshot, correction, parallel check.
- Task 8: Self-service/reporting/export/notices + Vue 3 frontend.
- Task 9: Migration/cutover tooling + seed data + deployment assets.
- Task 10: Joint audit, integration/E2E/security/perf verification, acceptance evidence (honest pass/gate status).

## Review ledger

(no tasks complete yet)
