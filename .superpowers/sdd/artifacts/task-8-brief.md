# Task 8 — Frontend, self-service, reporting, notices, exports

## Context

Backend modules through Payroll exist. Read `.superpowers/sdd/artifacts/interface-freeze.md`.

Authoritative sources: solution §7.6–7.8, §10.3–10.5; plan §4.9, §5.2, §6.4–6.5; UAT-09, UAT-10, UAT-13 (export/notice parts).

The goal: a usable Vue 3 + TypeScript + Vite + Element Plus frontend that consumes the OpenAPI contract, plus backend Engagement/Reporting modules. Everything must use the same authorization predicates and field projections as the APIs. No client-side permission as truth.

## Frontend technical baseline

- `frontend/` with Vue 3, TypeScript strict, Vite, Element Plus, Pinia, Vue Router, Axios, Zod or generated types, Vitest, Playwright.
- Lockfile committed (`pnpm-lock.yaml`). Node LTS. No CDN dependencies.
- OpenAPI type generation script produces `src/generated/api.ts`; CI fails if generated types drift.
- App shell: login, session expiry handling, CSRF token handling, role-aware navigation (server capabilities drive menu), error boundary, loading/empty/error states, locale zh-CN with English fallback.
- Accessibility: keyboard navigation, labels, focus states, contrast >= 4.5:1 for text, no color-only status.
- Responsive: usable at 360px and 1440px; tables scroll rather than break layout.
- No secrets in frontend; no localStorage tokens (session cookie only).

## Pages (minimum)

1. Login (password + TOTP step-up), session-expired dialog, MFA enrollment.
2. My workspace: profile, contract summary, attendance this month, leave balances, my requests, my payslips, notices.
3. Employee directory: search/filter by org, as-of date picker, paginated table; masked fields per profile; detail drawer with timeline.
4. Org tree: as-of date, expand/collapse, headcount badges; move impact preview dialog.
5. Case center: my requests, my approvals, all (scoped), status tabs; detail with SEPARATE application status and effect status; timeline; approve/reject/return with comment; retry effect for admins.
6. Leave: request form with balance preview and conflict check; cancel; ledger drill-down ("why this number").
7. Attendance: calendar month view, exceptions, correction request.
8. Payroll: runs list, run detail with state actions, employee results, reconciliation panel; my payslips.
9. Admin: users, roles, capability matrix (read-only catalog), bindings with anchor/field/purpose/expiry, access review, audit search.
10. Notices: list, detail, publish/manage for authorized users, read acknowledgement.
11. Dashboard: headcount, probation, turnover, pending approvals, contract expiries, recruitment funnel; each KPI links to an identical-predicate drill-down.
12. Reports: filter, table, chart, export button with purpose prompt.

## Backend Engagement/Reporting

- `notices`: id, tenant_id, title, body, audience_json limited to org/role/all, publish_at, retract_at, created_by, status. `notice_acknowledgements`.
- `notifications`: in-app notifications (user_id, type, payload with IDs only, read_at). External channels via Outbox adapters (log/mail stub), no real provider required.
- `report_definitions`: code, name, source_projection, as_of_supported, fields_json with field profiles, filters_json, drilldown_route.
- `report_runs`/`export_jobs`: async export job with status, row_count, file_object_id, purpose, expires_at, download_count, requested_by.
- `dashboard_snapshots`: metric_code, as_of, value, computed_at, source_hash; rebuildable.
- Reporting reads only projections/read models or module contracts, not arbitrary business tables. Implement at least: headcount by org, probation count, contract expiring in 30/60/90 days, pending approvals by age, leave liability, payroll cost by entity, recruitment funnel.
- Every metric must have a drill-down endpoint using the same query builder and authorization constraint. Test equality of count and list IDs.

## Exports

- Async export: POST creates job with purpose (enum), fields whitelist, filters; requires `reporting.export` AND field export profiles; rate limited; returns 202.
- Worker generates CSV (UTF-8 BOM for Excel), stores in private storage, returns short-lived signed download URL (or local test route with token), max 5 downloads, expiry 24h, watermark column with requester and timestamp, audit each download.
- Row cap 50k default; larger requires approval and splits into parts.
- Hidden/P3 fields never appear; test with a payroll export.

## Frontend tests

- Vitest unit: permission-driven navigation, field masking component, status badge separation (application vs effect), currency formatting from integer cents, date/timezone formatting, form validation.
- Playwright E2E (real backend, seeded demo DB):
  - login -> dashboard -> drill-down equality
  - employee directory as HR vs manager vs employee (scope differences)
  - leave request -> manager approval -> balance updated -> cancel -> reversal
  - transfer case -> approved-not-effective state -> effect applied -> as-of views
  - payroll run prepare -> review -> approve -> publish -> employee sees own payslip; another employee gets 404
  - export -> download -> audit record; hidden field absent
  - admin changes binding -> user's next request loses access (403/404)
- Accessibility smoke with axe on login, dashboard, case detail, payroll run; zero critical violations.
- Visual regression optional; at minimum screenshot smoke of 6 pages.

## Contracts

- Update `docs/openapi/openapi.yaml` for all new endpoints.
- `pnpm openapi:generate` regenerates types; CI diffs.
- `docs/05-ui-guidelines.md` documents layout, status language (申请状态 vs 生效状态), masking, error copy, accessibility, and responsive rules.
- `docs/09-test-acceptance.md` gets E2E scenario matrix.

## Constraints

- No permission logic duplicated in frontend as authority; UI hides but backend enforces.
- No token in localStorage; CSRF header from server-issued value.
- No placeholder lorem ipsum; real Chinese labels and empty states.
- No chart library that blocks build offline; use ECharts or Element Plus charts with pinned versions.
- All displayed money from integer cents; all displayed times from UTC with tenant timezone.
- Frontend must build with `pnpm build` and pass `pnpm typecheck`, `pnpm lint`, `pnpm test:unit`.
- Backend must pass full suite plus new reporting tests.
- Docker build for frontend (nginx static) included.

## Report
Write `.superpowers/sdd/artifacts/task-8-report.md` with files, exact command results (include E2E run output summary), decisions, gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
