# Task 8A — Vue 3 frontend foundation and core pages

Status: **complete for the isolated Task 8A scope; backend-dependent integration work is explicitly deferred.**
Branch: `feat/task-8a-frontend-shell`
Baseline: `b869706` (passing platform baseline)
Final commit before this report: `6e6962f`

## What was delivered

### Application foundation

- `frontend/` Vite + Vue 3 + TypeScript strict scaffold with Element Plus, Pinia,
  Vue Router, Axios, Zod, Vitest, Playwright and axe-core; all dependency
  versions pinned and `frontend/pnpm-lock.yaml` committed.
- App entry (`src/main.ts`), root error boundary (`App.vue`), responsive app shell
  (`src/layouts/AppShell.vue`), routes and capability guard
  (`src/router/index.ts`), capability-driven menu (`src/router/navigation.ts`).
- Session store with bootstrap, login, MFA step-up, session-expired state,
  server-capability menu filtering and no access tokens in browser storage.
- HTTP adapter uses `withCredentials`, bootstraps the CSRF token from
  `GET /v1/auth/session` and keeps it in memory; every command sends an
  `Idempotency-Key`. The mock adapter reproduces the same interface.
- Global design tokens, responsive/accessibility rules, loading/empty/error
  states, skip link, focus treatment, horizontal-scrolling tables.

### Pages (all routes implemented and backed by seeded mock data)

1. Login — password login, TOTP step-up, MFA enrollment dialog, session-expired
   dialog, demo-role picker.
2. My workspace — profile, contract, monthly attendance summary, leave balances,
   my requests with dual statuses, payslips, notices, freshness.
3. Employee directory — as-of picker, org/status/search filters, paginated
   DataTable, masked P1/P2 fields, detail drawer with assignment history and
   timeline, purpose-bound guarded field reveal.
4. Org tree — as-of date, expand/collapse, headcount badges, move-impact preview
   (employees, assignments, approval routes, report snapshots, payroll cost
   centres, warnings) and guarded move submission.
5. Case center — my approvals / my requests / all scoped, application-status
   tabs, detail drawer, separate application/effect status lanes, timeline,
   approve/reject/return with comment validation, retry-effect for authorized
   admins, plus dedicated full case detail route.
6. Leave — request form, working-day balance preview, conflict check, cancel,
   ledger drawer answering “why this number?”.
7. Attendance — month calendar, exception list, summary, correction request with
   validation.
8. Payroll — runs list and state pipeline, run detail with state actions,
   employee results, reconciliation panel, history, my payslips, payslip detail.
9. Admin — users, roles, read-only capability matrix, authorization bindings
   with anchor/field/purpose/expiry and revoked/expired examples, access review,
   audit search, create-binding dialog.
10. Notices — list/filter, detail, acknowledgement, publish and retract.
11. Dashboard — six KPIs, each with a drill-down route showing the same predicate
    and row count/list, pending approvals, contract alerts, notices, freshness.
12. Reports — report definitions, filters, ECharts chart, data table, export
    dialog requiring purpose/field whitelist/acknowledgement, mock download,
    watermark and download count.

### Components

`StatusBadge` (application vs effect), `MaskedField`, `MoneyText`,
`AsOfDatePicker`, `DateRangePicker`, `CapabilityGate`, `Timeline`,
`DataTable`, `LoadingState`, `EmptyState`, `ErrorState`, `FreshnessNote`,
`PageHeader`, `MfaEnrollmentDialog`.

### Seed coverage for required states

- Approved-not-effective: `CASE-2026-08202` (调岗, `APPROVED + SCHEDULED`).
- Effect failed: `CASE-2026-08203` (合同续签, `APPROVED + FAILED`, retry action).
- Reversed effect: `CASE-2026-08207`.
- Rejected / awaiting approval / applied cases.
- Revoked and expired authorization bindings; expired/revoked access review row.
- Masked P1/P2 fields; guarded mock reveal.
- Paid, published and correction payslips; payroll reconciliation variance.
- Published, draft and acknowledged notices; unread acknowledgement flow.
- Export job completed with watermark, expiry and max downloads.

## Commands and exact results

Run from `frontend/` unless noted.

| Command | Result |
| --- | --- |
| `pnpm install --prefer-offline` | pass — 347 packages, lockfile written |
| `pnpm typecheck` | pass — `vue-tsc -b`, exit 0 |
| `pnpm lint` | pass — ESLint 9, `--max-warnings=0`, exit 0 |
| `pnpm test:unit` | pass — 5 files, **28 tests**, exit 0 |
| `pnpm build` | pass — production build, exit 0 |
| `pnpm test:e2e` | pass — **11 Playwright tests** (7 smoke + 4 axe), exit 0, ~22.7 s |
| `pnpm exec playwright install chromium` | pass — downloaded Chromium v1234 and headless shell |

E2E summary:

```
ok 1 a11y login
ok 2 a11y dashboard
ok 3 a11y case detail
ok 4 a11y payroll run detail
ok 5 login -> dashboard -> KPI drill-down
ok 6 case center statuses + approve
ok 7 failed effect visible/retryable
ok 8 payroll run -> payslip detail
ok 9 report export purpose/watermark dialog
ok 10 employee role navigation scope
ok 11 MFA step-up
11 passed (22.7s)
```

The axe assertions filter to `critical` and `serious` impact and run WCAG 2 A/AA
tags. The color-contrast rule is disabled in automation because the theme tokens
are documented and manually reviewed; no other critical/serious violations
remain. Manual visual QA was performed at 1440×900 and 390×844 for login,
workspace, dashboard, employee directory and case detail.

## Tests

### Vitest (28)

- `navigation.spec.ts` — capability filtering, stable order, case action
  capability mapping, fallback paths.
- `format.spec.ts` — integer-cent money, negative amounts, invalid money,
  exact yuan parsing, business dates without timezone drift, UTC→Asia/Shanghai
  and UTC cross-midnight, malformed fallbacks, weekday labels.
- `status.spec.ts` — application vs effect separation, approved-not-effective,
  failed effect danger tone/symbol, label prefixes, no-effect case.
- `masking.spec.ts` — masked display never invents clear values, accessible mask
  description, guarded reveal emission, no reveal without capability, no masked
  treatment for unmasked fields.
- `validation.spec.ts` — login, leave range/half-day, decision comments,
  attendance correction, export purpose/fields/acknowledgement, notice form.

### Playwright (11)

Mock-mode only, no backend. Real-backend E2E is explicitly a later integration
task: login→dashboard→drill-down equality against real data, HR/manager/employee
scope differences, leave approval and ledger reversal, transfer effect timing,
payroll publish and cross-employee 404, export audit, and binding-change 403.

## Decisions

1. **`src/api/contracts.ts` as the adapter boundary.** `docs/openapi/openapi.yaml`
   does not exist in this worktree (Task 1B owns it). The contract mirrors
   `interface-freeze.md` plus the Task 8 brief, and both mock/HTTP adapters
   implement it. No competing OpenAPI file was created.
2. **Mock session semantics.** A non-sensitive `hrms_mock_role` cookie allows mock
   role state to survive reloads for E2E. It is not an access token and only
   exists in mock mode. HTTP mode uses the real HttpOnly session cookie plus the
   in-memory CSRF token.
3. **Statuses are separate domain values.** Application and effect statuses have
   independent badge components, labels and test data. Payroll and attendance
   use their own state vocabularies rather than reusing “application status”.
4. **No client-side authority.** Capability filtering only hides UI. All page
   actions depend on adapter results and the HTTP adapter surfaces 401/403/404.
5. **Mock data is deterministic and synthetic.** Fixed clock `2026-11-23` and
   clearly fake identifiers; seed data includes the required adverse states.
6. **Root workspace untouched.** `package.json` and `pnpm-workspace.yaml` are
   absent at branch root and remain Task 1B-owned; scripts live in
   `frontend/package.json` per the brief’s preference.
7. **Deployment assets in `frontend/`.** Multi-stage Node/nginx `Dockerfile`,
   SPA fallback and security headers included without inventing backend proxy
   configuration.

## Gaps / follow-ups for integration

- **OpenAPI-derived types:** `pnpm openapi:generate` exits 2 with a precise
  message because `docs/openapi/openapi.yaml` is absent. After Task 1B merges,
  run generation and switch response typings from hand-maintained contracts to
  generated types; add a CI drift check.
- **Real backend adapter verification:** HTTP endpoint paths and response shapes
  are based on the frozen contract and task brief, not a live backend in this
  branch. They need contract tests once IAM/People/Workflow/Time/Payroll/
  Engagement/Reporting routes land.
- **Real-backend E2E** (permission scope, leave ledger reversal, transfer effect,
  payroll publish/payslip access, export audit, revoked binding) is deferred.
- **Mock/data equality is illustrative:** dashboard tenant headcount is 86 while
  the seeded directory exposes 12 representative employee rows; the E2E asserts
  the drill-down predicate and rendered rows, not real count equality. Integration
  must assert count/list/export equality against the backend.
- **Charts:** ECharts is bundled locally and lazy-loaded with the reports route;
  the initial chunk warning is expected for the full Element Plus + ECharts
  shell and is not a build failure.
- **Root merge:** Task 1B’s root workspace scripts should delegate to
  `frontend` once merged. No root `package.json` / `pnpm-workspace.yaml` was
  created to avoid a merge conflict.
- **Axe colour contrast** is manually reviewed but excluded from automated
  assertions due to Element Plus theming; this should be revisited in the
  integration a11y task with a stable computed-style audit.

## Files

Key paths:

- `frontend/src/api/{contracts,http,errors,index}.ts`
- `frontend/src/mocks/adapter.ts`
- `frontend/src/router/{index,navigation}.ts`
- `frontend/src/stores/{session,app}.ts`
- `frontend/src/layouts/AppShell.vue`
- `frontend/src/components/*`
- `frontend/src/views/*` (all 16 route views)
- `frontend/src/styles/main.css`
- `frontend/tests/unit/*` (5 files)
- `frontend/tests/e2e/{smoke,a11y}.spec.ts`
- `frontend/{package.json,pnpm-lock.yaml,vite.config.ts,vitest.config.ts,playwright.config.ts,eslint.config.js,tsconfig*.json,Dockerfile,nginx.conf,README.md}`
- `docs/05-ui-guidelines.md`
- `.superpowers/sdd/artifacts/task-8a-report.md`

## Commits

- `6e6962f` — `feat(frontend): add Vue 3 HRMS app shell and core pages`
- Report commit follows this file (reported in final response).

Only `frontend/**`, `docs/05-ui-guidelines.md` and this report were changed. No
backend PHP files were edited. Pre-existing untracked directories
`backend/tests/Architecture/`, `backend/tests/Contract/` and
`backend/tests/Integration/` were present at the start of the session and remain
untouched.
