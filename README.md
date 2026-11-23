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
- [ADR-0001: Modular monolith](docs/adr/0001-modular-monolith.md)
- [ADR-0002: Platform baseline](docs/adr/0002-platform-baseline.md)
- [OpenAPI contract](docs/openapi/openapi.yaml)

The approved solution and plan are authoritative; this README does not replace their governance and sign-off requirements.

## Demo credentials

_Demo credentials are not published in this reference repository._ Local seed data is synthetic. When the IAM task lands, demo accounts will be documented here with a warning that they are local-only and must never be enabled in a deployed environment.

## Honest scope status

| Area | Status |
| --- | --- |
| Platform envelope, request id, errors, health/meta | Implemented and tested |
| Audit, outbox, idempotency, file metadata | Core services implemented; HTTP surfaces pending |
| Module boundary and capability gates | Implemented and enforced |
| OpenAPI contract | Health/meta documented; every new route must extend it |
| IAM / Organization / People / Workflow / Time / Payroll / Talent / Engagement / Reporting | Not implemented in this baseline |
| Frontend | Not scaffolded |
| Production HA, IdP, KMS, MySQL PITR, pen-test | Organizational gates, not fabricated here |
