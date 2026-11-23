# Task 1B — Finish Platform engineering baseline

## Context

`backend/` now has a passing Platform core: request-id, response envelope, exception mapping, audit/outbox/idempotency/file metadata, health and meta endpoints. 28 tests pass, Pint and PHPStan are clean. The worker that built it stopped before finishing repository-level tooling and commands.

Read the existing code before changing anything. Do not rewrite working modules. Read `.superpowers/sdd/artifacts/interface-freeze.md` for conventions.

## Deliverables

### 1. Root project files
- `README.md`: what this repository is, honest scope status (reference implementation of approved HRMS V1.0 architecture; not a completed 30-week rollout), prerequisites (PHP 8.4, Composer 2, Node 24, pnpm, MySQL 8.4 for production / SQLite for local), exact setup commands, test commands, directory map, links to the solution/plan/ADRs, demo credentials note placeholder.
- `AGENTS.md`: short engineering rules — TDD, no authorization bypass, one writer per table, forward-only migrations, no money floats, document ADRs, run gates before commit.
- `.editorconfig`: UTF-8, LF, final newline; PHP 4 spaces; TS/Vue/JSON/YAML 2 spaces.
- Root `package.json` + `pnpm-workspace.yaml` declaring `frontend` workspace (frontend may not exist yet; scripts must not crash before it does — use a small existence guard in a Node script under `scripts/`).
- Root scripts: `typecheck`, `lint`, `test:unit`, `test:e2e`, `openapi:lint`, `openapi:generate`. Each prints a clear message and exits 0 only when the relevant package exists and ran; otherwise exits non-zero with "frontend not scaffolded yet". Do not silently pass.

### 2. Architecture and ADR documentation
Create `docs/01-architecture.md`:
- five-layer model: contract -> fact kernel -> decision/effect -> ledger -> projection (as in solution §4).
- module table with owned tables and allowed dependencies (IAM, Organization, People, Lifecycle, Workflow, Time, Payroll, Talent, Engagement, Reporting, Platform).
- dependency rule: modules depend only on `App\Shared\*` and other modules' `Contracts`; `Models` are private; Platform may not depend on business modules.
- request-id/audit/outbox/idempotency conventions and where they live.
- explicit list of what is intentionally absent (BPMN engine, microservices, condition_json, CUSTOM SQL).
Create `docs/adr/0001-modular-monolith.md` (status Accepted, context, decision, consequences, alternatives rejected) and `docs/adr/0002-platform-baseline.md`. Use the ADR template in plan §15.

### 3. Module boundary enforcement
Implement `backend/app/Console/Commands/ArchTestCommand.php` (`arch:test`) plus a reusable `App\Shared\Architecture\ModuleBoundaryScanner`:
- Scan `app/Modules/<Module>/**/*.php` for `use App\Modules\<Other>\...` imports and fully-qualified references.
- Allowed: same module, `App\Modules\Platform\Contracts\*` and `App\Modules\Platform\Services\*`? No — restrict Platform to `Contracts` and `Shared` only; also allow `App\Modules\Platform\Models\AuditEvent`/`OutboxEvent` only if your design intentionally centralizes them, and document that exception explicitly. Prefer a narrow allowlist constant with a reason string.
- Any other module's `Models`, `Services`, `Http` => violation; `Contracts` always allowed.
- Scan for `DB::table(` / `Schema::` usage outside `database/migrations` and `app/Modules/<own>` to catch cross-module table access where feasible (report unresolved as warnings).
- Test with a temp directory containing a synthetic violating file: scanner reports it; clean temp tree passes.
- Register command; `php artisan arch:test` exits 0 now.

### 4. Capability audit
Implement `backend/app/Console/Commands/CapabilityAuditCommand.php` (`capability:audit`):
- Enumerate all routes under `/v1` (excluding `up`, `storage/*`).
- Public allowlist: `v1/health`, `v1/meta` (exact). Every other route must have middleware whose name starts `capability:` OR a route default/gatherMiddleware match.
- Fail non-zero listing unguarded routes; succeed with a summary table (route, capability, guard status).
- Warn (not fail) when no negative-test manifest entry exists: read `backend/tests/Authorization/endpoint_matrix.php` (create with health/meta entries now, extend later).
- Unit test both clean and violation cases by registering a temp route in the test app.

### 5. OpenAPI
Create `docs/openapi/openapi.yaml` OpenAPI 3.1:
- info (title/version/description), servers, tags.
- securitySchemes: `sessionCookie` (apiKey in cookie `hrms_session`) and `csrfHeader` (`X-CSRF-Token`).
- schemas: Envelope, ErrorEnvelope, HealthData, MetaData, Pagination.
- paths: `/v1/health`, `/v1/meta` with exact response shapes matching implementation, including 503 health shape.
- common responses 401/403/404/409/422/429/500.
Create `deploy/scripts/openapi-lint.mjs`: dependency-free Node script that parses YAML? Node has no YAML built in. Add `yaml` as a root devDependency and a minimal `package.json` script, or implement a structural check that reads the file and validates required keys/paths with a small vendored parser — prefer installing `yaml` + `@redocly/cli` at root if network works; pin versions. The lint must actually fail on malformed YAML and on a path missing `responses`.
Add `backend/tests/Contract/OpenApiContractTest.php`: every `/v1` route in Laravel exists in OpenAPI (excluding documented internal routes), and health/meta response fixtures validate required keys.

### 6. CI and deploy skeleton
- `.github/workflows/ci.yml`: PHP 8.4, composer install --no-interaction, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`, `php artisan capability:audit`, `php artisan arch:test`; Node setup + pnpm install + `pnpm openapi:lint`; conditional frontend steps.
- `.github/workflows/security.yml`: `composer audit`, gitleaks, dependency review.
- `deploy/compose/docker-compose.yml`: nginx, php-app, php-worker, php-scheduler, mysql:8.4, redis:7, minio with healthchecks, named volumes, env placeholders, restart policies. Do not include real secrets.
- `deploy/docker/php.Dockerfile`: PHP 8.4-fpm-alpine or Debian; install pdo_mysql, bcmath, intl, mbstring, opcache, pcntl; composer multi-stage; non-root user; healthcheck-friendly.
- `deploy/docker/nginx.conf`, `deploy/docker/php.ini`, `deploy/docker/.dockerignore`.
- `deploy/scripts/backup_restore_smoke.sh`: `set -euo pipefail`; takes SQLite path (default backend/database/database.sqlite); copies to temp, computes sha256, restores to temp, compares hash, prints PASS/FAIL and exits accordingly. Must be run by a PHPUnit integration test using a temp SQLite database.
- `.env.example` updates for MySQL/Redis/S3 and `APP_TIMEZONE=UTC`; keep SQLite default for local.

### 7. Tests
- `ArchTestCommandTest` (clean + synthetic violation)
- `CapabilityAuditCommandTest` (clean + synthetic unguarded route)
- `BackupRestoreSmokeTest` (PHPUnit, shells out, temp files, cleanup)
- `OpenApiContractTest`
- `ModuleScannerTest` unit tests
Run full suite, pint, phpstan, arch:test, capability:audit, openapi:lint. Report exact results.

## Constraints
- Do not modify approved solution/plan markdown.
- No committing `.env`, `vendor/`, `node_modules/`, SQLite DB files.
- Bash on Windows; PHP at `/d/code/ai/factoryHR/.tools/php/php.exe`; composer at `/d/code/ai/factoryHR/.tools/composer2.phar`; `COMPOSER_HOME=/d/code/ai/factoryHR/.tools/composer-home`.
- Commit in logical slices with conventional messages.

## Report
Write `.superpowers/sdd/artifacts/task-1b-report.md` with files, exact commands/results, decisions, gaps, commits. Return status, hashes, one-line test summary, concerns.
