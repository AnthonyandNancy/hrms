# Task 1 — Engineering baseline and Platform module

## Context

Greenfield HRMS modular monolith. Laravel 13.17 skeleton already exists at `backend/` and boots with SQLite. The approved plan requires a single repository with `backend/`, `frontend/`, `deploy/`, `docs/`, `.github/workflows/`. Use SQLite for the reference implementation's automated tests and developer boot; MySQL 8.4 stays the declared production target and must be provided in Docker Compose.

Authoritative requirements: `docs/HRMS_V1.0_解决方案_第一性原理重构.md` §10 and `docs/HRMS_V1.0_实施计划_30周路线图.md` §2, §4.1, §6.

## Required deliverables

### 1. Repository and tooling
- Root `README.md` with purpose, prerequisites, setup, test, and scope-status sections. State plainly that this is a reference implementation of the V1.0 architecture, not a completed 30-week production rollout.
- Root `AGENTS.md`: short agent instructions (run tests before commit, never bypass authorization, no business logic outside modules, migrations only forward, document ADRs).
- Root `package.json` with pnpm workspace scripts: `typecheck`, `lint`, `test:unit`, `test:e2e`, `openapi:lint`, `openapi:generate` (frontend may be added in Task 8; scripts must fail clearly if frontend absent, OR be wired now to a placeholder that succeeds only when frontend exists — prefer wiring that will work after Task 8).
- `composer.json` at root is optional; keep backend commands canonical and document them.
- Add `.editorconfig` at root (LF, UTF-8, 4 spaces PHP, 2 spaces TS/Vue).
- Add `phpstan.neon` (level 6) and `pint.json` in `backend/`; add `larastan/larastan` dev dependency compatible with Laravel 13.
- Add `backend/phpunit.xml` test suites: Unit, Feature, Contract, Integration.
- Root `Makefile` or `justfile` is optional; documented commands are mandatory.

### 2. Response/error/request-id baseline
Implement under `backend/app/Shared/`:
- `ApiResponse.php`: static `ok($data, int $status=200)`, `created`, `accepted`, `error(string $code, string $message, int $status, array $details=[])`. Envelope exactly `{code, message, data, request_id}`; list payloads include `items,page,page_size,total`.
- `RequestId.php` middleware: accept inbound `X-Request-Id` only if it matches `^[A-Za-z0-9._:-]{8,64}$`; otherwise generate ULID. Make it available via request attribute, response header, log context, and a container-scoped accessor.
- `ErrorCode.php`: backed string enum with at least UNAUTHENTICATED(401), FORBIDDEN(403), NOT_FOUND(404), CONFLICT(409), VALIDATION_FAILED(422), RATE_LIMITED(429), INTERNAL(500), SERVICE_UNAVAILABLE(503).
- Exception renderer mapping Laravel auth/validation/404/405/429 and `DomainException` subclasses to the envelope. Never leak stack traces, SQL, file paths, or class names in production responses.
- `DomainException` base + `ConflictException`, `NotFoundOrForbiddenException` (must render 404 to prevent enumeration), `ValidationException` domain wrapper if needed.
- Ensure error responses also carry `request_id` and `X-Request-Id`.

### 3. Platform persistence and services
Migrations + models + services for:
- `audit_events`: append-only. Columns: id, tenant_id, actor_user_id nullable, actor_type, action, aggregate_type, aggregate_id, before_json, after_json, metadata_json, ip, user_agent, request_id, occurred_at. No update/delete API. Model blocks update/delete in `booted()`.
- `outbox_events`: id (ULID string), tenant_id, event_type, aggregate_type, aggregate_id, aggregate_version, payload_json, occurred_at, available_at, published_at nullable, attempts, last_error, created_at. Dispatcher interface + `OutboxService::record()` that must participate in the caller's transaction.
- `idempotency_keys`: tenant_id, principal_id, scope, key, request_hash, status (`IN_PROGRESS|COMPLETED|FAILED`), response_status, response_body_json, resource_type, resource_id, expires_at, created_at; unique `(tenant_id, principal_id, scope, key)`. Service that reserves a key, detects same-key-different-payload as 409, replays completed responses, and releases failed keys.
- `file_objects`: metadata only (storage key, sha256, mime, size, owner, purpose, retention_class, legal_hold, created_at). No public URLs.
- `projections` optional — skip unless needed now.

### 4. Health and meta endpoints
- `GET /v1/health` — returns `{status, version, checks:{db, cache, storage}, time}`. HTTP 200 only if db ok; cache/storage degradation makes status `degraded` but still 200; DB failure => 503 with stable envelope. Must be unauthenticated.
- `GET /v1/meta` — version, environment, server_time, supported_timezones subset, api_version `v1`.
- Register `routes/api.php` with `/v1` prefix in `bootstrap/app.php`. Remove the skeleton's unused web page or make `/` return the API meta.

### 5. Module boundary enforcement
- Create `backend/app/Modules/{IAM,Organization,People,Lifecycle,Workflow,Time,Payroll,Talent,Engagement,Reporting,Platform}/` with `.gitkeep` only where no code yet.
- Add `backend/tests/Architecture/ModuleBoundaryTest.php` (PHPUnit, no external arch library required): scan `app/Modules/*` and assert no module references another module's `Models` namespace directly except allowed read contracts. Allow only `Platform` to be referenced by all; allow `Shared`. Document allowed dependencies in `docs/01-architecture.md`.
- Implement `php artisan arch:test` command that runs the same boundary checker and exits non-zero on violation.
- Implement `php artisan capability:audit` as a command that scans registered routes and fails if any `/v1` route outside an explicit public allowlist lacks `capability:` middleware metadata. For Task 1 there will be only health/meta; create the allowlist mechanism and make the command pass now, ready to catch Task 2+ routes.

### 6. OpenAPI contract source
- `docs/openapi/openapi.yaml` OpenAPI 3.1 with servers, security scheme placeholder (session cookie + CSRF header), common envelope schemas, error responses, `/v1/health`, `/v1/meta`.
- Add a lightweight validator script `deploy/scripts/openapi-lint.mjs` using `@redocly/cli` via `pnpm dlx` OR a hand-rolled structural validator if network-free is preferred. It must exit non-zero on malformed YAML/missing paths. Root `pnpm openapi:lint` calls it.
- Document that OpenAPI is the single contract source.

### 7. CI and Docker
- `.github/workflows/ci.yml`: PHP 8.4 setup, composer install, pint test, phpstan, artisan test, capability audit, arch test, OpenAPI lint, Node setup + pnpm install/lint/typecheck when frontend exists.
- `.github/workflows/security.yml`: composer audit, pnpm audit (non-blocking until frontend exists), secret scan via gitleaks action.
- `deploy/compose/docker-compose.yml`: nginx, php-app, php-worker, php-scheduler, mysql:8.4, redis:7, minio; healthchecks; named volumes; non-root app container where practical.
- `deploy/docker/php.Dockerfile` (multi-stage, PHP 8.4-fpm, extensions pdo_mysql, bcmath, intl, mbstring, opcache, redis via pecl optional — keep build reliable), `deploy/docker/nginx.conf`, `deploy/docker/php.ini`.
- `deploy/scripts/backup_restore_smoke.sh` that works with SQLite for local verification and prints clear PASS/FAIL. It must actually copy, restore, and compare a checksum, not just echo.
- `.env.example` updated for MySQL/Redis/S3 with safe placeholder values and `APP_TIMEZONE=UTC`.

### 8. Tests (must pass)
Feature:
- `HealthTest`: 200 envelope, request_id present and echoed, DB down path returns 503 (mock/fake the check rather than killing DB).
- `MetaTest`.
- `RequestIdTest`: valid inbound preserved; invalid replaced; response header always present.
- `ErrorEnvelopeTest`: 404, 422, 405, 429 produce envelope with code/message/request_id and no internals.
Unit:
- `IdempotencyServiceTest`: first call executes, replay returns stored response, same key different payload => 409, failed key reusable.
- `AuditAppendOnlyTest`: update/delete throws.
- `OutboxServiceTest`: record inside transaction rolls back with transaction; payload has no prohibited keys (`id_number`, `password`, `bank_account`).
Integration:
- `ModuleBoundaryTest` via artisan command exits 0 on clean tree and non-zero on a synthetic violation (write the synthetic file to a temp dir or inject a scanner path - do not leave a violating file in the repo).
- Backup restore smoke executed by a PHP test that shells out to the script using a temp SQLite DB.

## Constraints
- PHP 8.4, Laravel 13, strict types in all new PHP files.
- No float for money anywhere; this task introduces no money logic.
- All timestamps UTC; business dates DATE.
- No secrets committed; `.env` git-ignored.
- Do not modify the approved docs under `docs/*.md` except adding new files (`docs/01-architecture.md`, OpenAPI).
- Preserve existing passes; do not delete the example tests unless replaced by real ones.
- Run: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan capability:audit`, `php artisan arch:test`.
- PATH for PHP: prepend `/d/code/ai/factoryHR/.tools/php`. Composer: `php /d/code/ai/factoryHR/.tools/composer2.phar` with `COMPOSER_HOME=/d/code/ai/factoryHR/.tools/composer-home`.

## Exit criteria
All listed tests green; commands above exit 0; docs/01-architecture.md explains dependency rules; no route bypasses the capability-audit mechanism; a fresh `php artisan migrate:fresh --seed` succeeds.

## Report
Write `.superpowers/sdd/artifacts/task-1-report.md` with: files created, commands run + exact results, design decisions, known gaps, and `git log --oneline` for your commits. Commit with conventional messages. Return only: status, commit hashes, one-line test summary, concerns.
