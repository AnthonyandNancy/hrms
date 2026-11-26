# HRMS V1.0 — 生产基线

本仓库是 HRMS V1.0 的生产基线：已批准架构与实现按同一套代码、同一套门禁交付，默认安装路径不依赖任何演示数据。

基线包含以下已落地能力：

- modular monolith with enforceable module boundaries;
- `/v1` response envelope, stable error codes and request correlation;
- audit, outbox, idempotency and file metadata primitives;
- public health and metadata endpoints with an OpenAPI 3.1 contract;
- architecture, capability-coverage and contract quality gates;
- CI, deployment and backup/restore skeletons.

业务域（IAM 授权、人员/任职、Workflow、假勤、薪酬、Talent、通知、报表）均在本基线内交付；`Talent` 目前只有后端切片，尚无对应前端页面，详见下方状态表。

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

Local development uses SQLite (`DB_CONNECTION=sqlite`, `backend/database/database.sqlite`). Set `APP_TIMEZONE=UTC`; business dates are evaluated in the `company_profile` IANA timezone by application code, never by changing the process timezone.

The product is a **single-company instance**: there is no tenant, legal entity or company switcher (ADR-0034). Company name, timezone and currency live in the singleton `company_profile` table.

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

## First run

The supported production birth path is the idempotent installer. It creates
reference data and exactly one administrator, and **zero** employees, payroll
runs or workflow cases:

```bash
cd backend
php artisan hrms:install   --legal-entity-code=ACME-CN --legal-entity-name="Acme China"   --country=CN --timezone=Asia/Shanghai --currency=CNY   --admin-username=installer --admin-email=installer@example.test   --admin-password="$(cat /run/secrets/admin_password)"   --annual-days=10.00 --legal-basis="Company policy appendix A"
```

默认配置下（`IAM_MFA_ENABLED=false`）登录只需账号与密码，安装器不创建 TOTP 因子，
`POST /v1/auth/login` 直接签发会话（见 ADR-0018）。设置 `IAM_MFA_ENABLED=true`
后，特权账号与持有 P2/P3 绑定的账号会被要求先完成 `POST /v1/auth/mfa/challenge`，
安装器也会在控制台打印一次性的 TOTP 密钥与恢复码。

`deploy/scripts/docker_up.sh` runs the installer by default. It requires
`ADMIN_PASSWORD`, `LEGAL_ENTITY_CODE`, `LEGAL_ENTITY_NAME`, `ADMIN_USERNAME`,
`ADMIN_EMAIL`, `ANNUAL_DAYS` and `LEGAL_BASIS`.

## Optional demo dataset

Demo accounts are **local-only** and must never be enabled in a deployed
environment. `docker_up.sh --demo` (or the commands below with an
operator-supplied `DEMO_SEED_PASSWORD`, at least 12 characters) loads the
synthetic dataset; this is never the default path:

```bash
cd backend
php artisan iam:seed-demo      # 合成演示账号（仅本地）
php artisan people:seed-demo   # synthetic org, people, assignments and terms
php artisan talent:seed-demo
```

`demo:seed` runs in **SHADOW** mode: domains owned by parallel modules are
rehearsed and reconciled but not merged into live business tables, and
`demo:verify` reports that honestly.

## Honest scope status

代码证据与组织级门禁分别记录在 `evidence/acceptance-report.md`。下表只描述
本仓库当前可验证的实现状态，未完成的外部依赖单独列出，不做"已完成"表述。

| Area | Status |
| --- | --- |
| Platform envelope, request id, errors, health/meta | Implemented and tested |
| Audit, outbox, idempotency, file metadata | Audit and outbox HTTP query surfaces implemented; file metadata read-only |
| Module boundary and capability gates | Implemented and enforced (`arch:test`, `capability:audit`) |
| OpenAPI contract | 127 paths / 141 operations; lint 与 Redocly 校验通过 |
| IAM (sessions, MFA, break-glass, access review) | Implemented and tested |
| Organization, People, terms, assignments | Implemented and tested, as-of reads |
| Workflow Case–Decision–Effect | Implemented and tested, closed case-type → template mapping |
| Time, attendance, leave ledger | Implemented and tested; ledger reconciled |
| Payroll runs, corrections, payslips | Implemented and tested; bank/tax CSV adapters only |
| Recruitment / performance / training thin slices | 后端切片已实现并有测试；**前端无页面**，不作为可用功能对外承诺 |
| Engagement notices, reporting, exports | Implemented and tested |
| Frontend (Vue 3 + TS strict) | 已实现；52 个单元测试，mock 与真实后端 E2E |
| Payroll parallel run against a real legacy export | **Not executed** — no legacy file; command exits 2 by design |
| Real IdP/OIDC, bank/tax filing, payslip PDF, HA, KMS, MySQL PITR, pen-test, S-01–S-08 sign-offs | Organizational/external gates, not fabricated here |
