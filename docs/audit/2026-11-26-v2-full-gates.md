# FactoryHR V2.1 — Authoritative full-gate matrix

Date: 2026-11-27
Branch: `feat/v2.1-remediation`
Measured at commit: `9e9de01`
Command: `pnpm gate:all` (one fail-fast run from the repository root)

## Why one command

Before this volume the gates were run by hand from different directories, with
two known traps: `php artisan test` OOMs at the 128 MB php.ini limit, and a
PHPUnit run started from the wrong directory loads two Composer autoloaders and
scans the other worktree. Both are now encoded in the aggregate script:

- backend gates always run `.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit`
- every gate runs with an explicit working directory
- the runner stops at the first non-zero exit and contains no `|| true`

`AGENTS.md` rule 9 points at `pnpm gate:all` as the authoritative matrix.

## Result: GATE:ALL PASS (22 gates, 230.8s)

| # | Gate | Command | Exit | Observed summary | Time |
| --- | --- | --- | --- | --- | --- |
| 1 | backend:phpunit | `.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never` | 0 | OK (1278 tests, 58488 assertions) | 109.7s |
| 2 | backend:pint | `vendor/bin/pint --test` | 0 | PASS 1196 files | 2.0s |
| 3 | backend:phpstan | `.tools/php/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress` | 0 | [OK] No errors | 9.1s |
| 4 | backend:capability | `artisan capability:audit` | 0 | Capability audit OK. Public routes (allowlist): 6 | 0.9s |
| 5 | backend:arch | `artisan arch:test` | 0 | Module boundaries OK. Warnings (non-blocking): 4 | 1.2s |
| 6 | contract:openapi-lint | `node deploy/scripts/openapi-lint.mjs` | 0 | OK (263 paths, 338 operations, 3.1.0) | 0.5s |
| 7 | contract:openapi-validate | `pnpm openapi:validate` | 0 | valid, 2 warnings (health/meta) | 2.6s |
| 8 | contract:actions-check | `pnpm actions:check` | 0 | OK (155 pages, 36 actions, 149 write gaps, 13 capability gaps) | 1.2s |
| 9 | contract:action-manifest | `node scripts/audit_action_manifest.mjs` | 0 | OK | 0.7s |
| 10 | contract:openapi-check | `pnpm openapi:check` | 0 | generated client current | 3.3s |
| 11 | frontend:typecheck | `pnpm typecheck` | 0 | vue-tsc clean | 15.9s |
| 12 | frontend:lint | `pnpm lint` | 0 | eslint clean (0 warnings) | 10.5s |
| 13 | frontend:unit | `pnpm test:unit` | 0 | 71 files / 599 tests passed | 43.1s |
| 14 | frontend:build | `VITE_API_MODE=http pnpm run build` | 0 | build succeeded | 26.8s |
| 15 | product:page-model | `node scripts/audit_page_model.mjs` | 0 | ok | 0.2s |
| 16 | product:ui-primitives | `node scripts/audit_ui_primitives.mjs` | 0 | ok | 0.2s |
| 17 | product:user-copy | `node scripts/audit_user_copy.mjs` | 0 | ok | 0.2s |
| 18 | product:page-components | `node scripts/audit_page_components.mjs` | 0 | 155/155, 149 module + 6 base | 0.2s |
| 19 | product:route-coverage | `node scripts/audit_route_coverage.mjs` | 0 | ok | 0.4s |
| 20 | product:frontend-modules | `node scripts/audit_frontend_modules.mjs` | 0 | failures: [] | 0.2s |
| 21 | product:single-company | `node scripts/audit_single_company.mjs` | 0 | ok | 0.4s |
| 22 | e2e:collect | `playwright test --config=playwright.real.config.ts --list` | 0 | 52 tests in 15 files | 1.6s |

## EXECUTED vs COLLECTED

- **EXECUTED**: gates 1–21. Every one produced a real summary line above.
- **COLLECTED only**: gate 22. Playwright lists 52 tests across 15 real-stack
  specs, but **no real-stack execution has been performed** — it requires a
  seeded stack and `DEMO_SEED_PASSWORD`, which this environment does not have
  configured. Any claim of a passing real-stack UAT must come from Volume 4
  Task 9 with its own evidence file; this document does not make that claim.

## Fail-fast proof

The runner was tested by injecting a failing first gate: it exited 1 and zero
later gates ran. There is no swallow (`|| true`) anywhere in the gate list.

## Notes / non-blocking

- `openapi:validate` reports 2 warnings on the health/meta endpoints. They
  predate this volume and are not errors; `openapi-lint` is clean.
- `arch:test` reports 4 non-blocking warnings. They predate this volume.
- The backend suite grew from 1246 to 1278 tests during Volume 4 Wave A
  (account security, settings workspace, workflow decisions, notification
  capability unification). Frontend grew from 546 to 599.
