# HRMS Frontend (Task 8A)

Vue 3 + TypeScript (strict) + Vite reference frontend for the HRMS V1.0
architecture. It implements the app shell, session/capability-driven navigation,
and the core self-service, workflow, time, payroll, governance, notices,
dashboard and reporting pages against the frozen interface contract.

## Quick start

```bash
cd frontend
pnpm install
pnpm dev            # http://127.0.0.1:5173, VITE_API_MODE=mock by default
```

Demo accounts (mock adapter, password `Demo!2026` for all):

| Role | Email | Notes |
| --- | --- | --- |
| Administrator | `admin@demo.example` | Full governance, payroll, reporting and export |
| HR administrator | `hr@demo.example` | People, workflow, time and payroll runs; no IAM/audit |
| Employee | `jiahe.chen@demo.example` | Self-service only |
| MFA demo | `mfa@demo.example` | Shows password + TOTP step-up; any 6 digits except `000000` |

The mock adapter is deterministic and seeded with realistic synthetic data
covering approved-not-effective cases, failed effects, revoked/expired
authorization bindings, masked P1/P2 fields, published/correction payslips and
export jobs. No real personal data is used.

## API modes

`src/api/contracts.ts` is the typed adapter boundary. Both adapters implement
the same `ApiAdapter`; pages never import mock data or Axios directly.

- `VITE_API_MODE=mock` (default): `src/mocks/adapter.ts`, no backend required.
- `VITE_API_MODE=http`: `src/api/http.ts`, session-cookie auth with
  `withCredentials`, CSRF token bootstrapped from `GET /v1/auth/session` and
  kept **in memory only**. There is no token in `localStorage` or
  `sessionStorage`. Every command sends `Idempotency-Key`.

For local HTTP mode, run Laravel on `127.0.0.1:8000`; Vite proxies `/v1`.
The backend OpenAPI file (`docs/openapi/openapi.yaml`) is owned by Task 1B and
is not merged into this branch. `src/api/contracts.ts` mirrors
`interface-freeze.md` plus the Task 8 brief. When the contract lands, run
`pnpm openapi:generate` and replace the generated types rather than creating a
competing spec.

## Commands

```bash
pnpm install
pnpm typecheck
pnpm lint
pnpm test:unit
pnpm build
pnpm test:e2e          # Playwright smoke + axe, mock mode, no backend
pnpm test:e2e:install  # install Chromium if missing
pnpm openapi:generate  # requires docs/openapi/openapi.yaml (Task 1B)
```

Unit tests cover permission-driven navigation, masking, application/effect
status separation, integer-cent money formatting, UTC→tenant-timezone
formatting, date handling and form validation.

Playwright covers login → dashboard → KPI drill-down, case approve with dual
status, failed-effect retry affordance, payroll run → payslip detail, report
export purpose dialog, role-based navigation, MFA step-up and an axe smoke on
login/dashboard/case detail/payroll run. Real-backend E2E remains an integration
task (see `docs/05-ui-guidelines.md` and the Task 8A report).

## Structure

```
src/
  api/          typed contract, HTTP adapter, API errors
  components/   StatusBadge, MaskedField, MoneyText, AsOfDatePicker,
                CapabilityGate, Timeline, DataTable, states, shell furniture
  composables/  async state and API error feedback
  layouts/      responsive app shell
  mocks/        deterministic seeded adapter
  router/       routes + capability-driven navigation
  stores/       session + shell counters
  styles/       design tokens, layout, status language, responsive rules
  views/        login, workspace, directory, org, cases, leave, attendance,
                payroll, payslips, admin, notices, dashboard, reports
tests/
  unit/         Vitest
  e2e/          Playwright smoke + axe
```

## Design and accessibility

UI copy is zh-CN with English technical fallbacks (capability codes, field
profile codes, request ids). Application status and effect status are always
rendered as two independent, labelled lanes; there is no single green
"completed" status. Status is conveyed by text and symbol as well as color.
Tables scroll horizontally rather than collapsing. All pages have visible
focus, semantic labels, skip navigation, live regions for async states and
loading/empty/error states.

See `../docs/05-ui-guidelines.md`.

## Task boundary

This is a reference implementation. Backend Engagement/Reporting endpoints,
schema generation and real-backend E2E are integration tasks. Root workspace
files are owned by Task 1B; this worktree intentionally does not create a
competing root `package.json` or `pnpm-workspace.yaml`.
