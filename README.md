# HRMS V1.0 — Reference Implementation

This repository is a **reference implementation of the approved HRMS V1.0 architecture**, not a completed 30-week production rollout.

It implements the architectural skeleton and the Platform foundation from the approved solution and plan:

- modular monolith with enforceable module boundaries;
- `/v1` response envelope, stable error codes and request correlation;
- audit, outbox, idempotency and file metadata primitives;
- public health and metadata endpoints with an OpenAPI 3.1 contract;
- architecture, capability-coverage and contract quality gates;
- CI, deployment and backup/restore skeletons.

Business domains (IAM authorization, person/employment, workflow, time, payroll, talent, engagement, reporting) are delivered by subsequent tasks. Empty module directories are intentional placeholders, not implemented features.

## Prerequisites

- PHP 8.4 with `pdo_sqlite`, `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `opcache`, `pcntl`
- Composer 2
- Node.js 24 and pnpm 10
- SQLite for local development; MySQL 8.4 for production parity
- Redis 7 and an S3-compatible object store (MinIO locally) for the full stack
- Bash and `sha256sum` for the backup/restore smoke script
- Docker with Compose v2 for the optional container stack; the PHP test suite does not require Docker

## Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Local development uses SQLite (`DB_CONNECTION=sqlite`, `backend/database/database.sqlite`). Set `APP_TIMEZONE=UTC`; business dates are evaluated in a legal entity's IANA timezone by application code, never by changing the process timezone.

From the repository root:

```bash
pnpm install --frozen-lockfile
pnpm openapi:lint
pnpm openapi:validate
```

The Compose stack validates with placeholder values and needs no committed secrets:

```bash
docker compose -f deploy/compose/docker-compose.yml config --quiet
cd deploy/compose
cp .env.example .env   # optional local overrides; ignored by git
```

Frontend commands (`typecheck`, `lint`, `test:unit`, `test:e2e`, `openapi:generate`) intentionally exit non-zero until `frontend/` is scaffolded; they never silently pass.

## Test and quality commands

Run from `backend/` unless noted:

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan capability:audit
php artisan arch:test
cd ..
pnpm openapi:lint
pnpm openapi:validate
```

`composer test` runs the Laravel suite after clearing cached config.

## Directory map

```text
backend/                 Laravel 13 application
  app/Modules/           One directory per bounded context
  app/Shared/            Cross-cutting primitives (no business rules)
  app/Console/Commands/  arch:test and capability:audit gates
  database/migrations/   Forward-only schema migrations
  routes/api.php         /v1 routes
  tests/                 Unit, Feature, Contract, Integration, Architecture
docs/
  01-architecture.md     Layer, ownership and dependency rules
  adr/                   Accepted architecture decision records
  openapi/               OpenAPI 3.1 contract source
deploy/
  compose/               Local/staging Compose stack
  docker/                PHP, Nginx and ini configuration
  scripts/               OpenAPI lint and backup/restore smoke checks
.github/workflows/       CI and security gates
scripts/                 Root workspace helpers
```

## Documentation

- [Solution: first-principles reconstruction](docs/HRMS_V1.0_解决方案_第一性原理重构.md)
- [30-week implementation plan](docs/HRMS_V1.0_实施计划_30周路线图.md)
- [Architecture overview](docs/01-architecture.md)
- [Permissions model](docs/02-permissions.md)
- [Data model](docs/03-data-model.md)
- [UI guidelines](docs/05-ui-guidelines.md)
- [Business rules](docs/07-business-rules.md)
- [Joint audit and acceptance plan](docs/10-joint-audit-acceptance.md)
- [Acceptance report (evidence)](evidence/acceptance-report.md)
- [OpenAPI contract](docs/openapi/openapi.yaml)
- [Cutover runbook](docs/runbooks/cutover.md)
- [Rollback runbook](docs/runbooks/rollback.md)
- [Hypercare runbook](docs/runbooks/hypercare.md)

### Architecture decision records

The approved plan (section 15) mandates ADR-0001–ADR-0015. All fifteen are Accepted:

| ADR | Topic |
| --- | --- |
| [ADR-0001](docs/adr/0001-modular-monolith.md) | Modular monolith with enforced module boundaries |
| [ADR-0002](docs/adr/0002-person-employment-assignment.md) | Person–Employment–Assignment and the legal-entity employer boundary |
| [ADR-0003](docs/adr/0003-time-semantics-and-business-calendar.md) | Four timelines, half-open validity, legal-entity business dates |
| [ADR-0004](docs/adr/0004-capability-anchor-authorization.md) | Capability × Anchor authorization with field profiles |
| [ADR-0005](docs/adr/0005-case-decision-effect.md) | Case–Decision–Effect with domain-owned lifecycle effects |
| [ADR-0006](docs/adr/0006-leave-ledger-and-close.md) | Append-only leave ledger in signed integer minutes |
| [ADR-0007](docs/adr/0007-payroll-run-correction-boundaries.md) | Payroll run states, immutable finalization and corrections |
| [ADR-0008](docs/adr/0008-outbox-idempotency-events.md) | Transactional outbox, idempotency keys and event contracts |
| [ADR-0009](docs/adr/0009-files-sensitive-data-privacy.md) | Files, sensitive data, export scope and privacy |
| [ADR-0010](docs/adr/0010-migration-and-cutover.md) | Migration order, shadow rehearsal and cutover |
| [ADR-0011](docs/adr/0011-deployment-observability.md) | Deployment, backup and observability baseline |
| [ADR-0012](docs/adr/0012-configuration-and-feature-release.md) | Configuration boundary and feature/capability release |
| [ADR-0013](docs/adr/0013-identity-session-mfa-breakglass.md) | Identity, sessions, MFA, SSO and break-glass |
| [ADR-0014](docs/adr/0014-payroll-calculation-rounding-statutory.md) | Payroll calculation, rounding and statutory parameters |
| [ADR-0015](docs/adr/0015-retention-deletion-legal-hold.md) | Retention, deletion and legal hold |

The approved solution and plan are authoritative; this README does not replace their governance and sign-off requirements.

## Demo credentials

Demo accounts are **local-only** and must never be enabled in a deployed
environment. They are seeded with an operator-supplied password
(`DEMO_SEED_PASSWORD`, at least 12 characters) and are not published here:

```bash
cd backend
php artisan iam:seed-demo      # demo.admin, demo.hr, demo.manager, demo.employee
php artisan people:seed-demo   # synthetic org, people, assignments and terms
php artisan workflow:seed-templates
php artisan talent:seed-demo
```

The demo dataset is synthetic. `demo:seed` runs in **SHADOW** mode: domains owned
by parallel modules are rehearsed and reconciled but not merged into live
business tables, and `demo:verify` reports that honestly.

## Honest scope status

This repository is a **reference implementation** of the approved V1.0
architecture, not a completed 30-week production cutover. Code evidence and
organizational gates are reported separately in
`evidence/acceptance-report.md`.

| Area | Status |
| --- | --- |
| Platform envelope, request id, errors, health/meta | Implemented and tested |
| Audit, outbox, idempotency, file metadata | Audit and outbox HTTP query surfaces implemented; file metadata read-only |
| Module boundary and capability gates | Implemented and enforced (`arch:test`, `capability:audit`) |
| OpenAPI contract | 125 paths / 139 operations; lint and Redocly validation green |
| IAM (sessions, MFA, break-glass, access review) | Implemented and tested |
| Organization, People, terms, assignments | Implemented and tested, as-of reads |
| Workflow Case–Decision–Effect | Implemented and tested, closed case-type → template mapping |
| Time, attendance, leave ledger | Implemented and tested; ledger reconciled |
| Payroll runs, corrections, payslips | Implemented and tested; bank/tax CSV adapters only |
| Recruitment / performance / training thin slices | Implemented and tested |
| Engagement notices, reporting, exports | Implemented and tested |
| Frontend (Vue 3 + TS strict) | Implemented; 28 unit tests, mock and real-backend E2E |
| Payroll parallel run against a real legacy export | **Not executed** — no legacy file; command exits 2 by design |
| Real IdP/OIDC, bank/tax filing, payslip PDF, HA, KMS, MySQL PITR, pen-test, S-01–S-08 sign-offs | Organizational/external gates, not fabricated here |
