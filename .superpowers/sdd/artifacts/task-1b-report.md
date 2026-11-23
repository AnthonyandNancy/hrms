# Task 1B Report — Finish Platform engineering baseline

Status: **DONE**
Worktree: `D:\code\ai\factoryHR\.worktrees\task-1b`
Branch: `chore/task-1b-baseline`
Base: `b869706` (passing 28-test Platform baseline)
HEAD: `a3f3c16`

## Summary

Task 1B finished the repository-level engineering baseline around the already-working Platform core without rewriting it:

- module boundary scanner and `arch:test` with explicit, currently-empty allowlist;
- `capability:audit` with exact health/meta allowlist and warning-only negative-test manifest;
- OpenAPI 3.1 contract, dependency-light structural lint and Redocly validation;
- root README/AGENTS/editorconfig and pnpm workspace with guarded frontend scripts;
- architecture documentation and ADR-0001/ADR-0002;
- CI, security workflow, Compose/PHP/Nginx skeleton and backup/restore smoke test;
- `.env.example` updates for MySQL/Redis/S3 and `APP_TIMEZONE=UTC`.

All final gates pass at `a3f3c16`.

## Commits

```text
f8933b9 feat(architecture): enforce module boundaries and capability audit
cabb78e feat(contract): add OpenAPI 3.1 spec and lint gate
78e806e chore(architecture): satisfy static analysis for boundary gates
8847cd6 docs: add architecture, ADRs and repository onboarding
16c54a5 chore(deploy): add CI, container skeleton and backup smoke check
07e61e8 chore(qa): avoid PHPStan worker memory exhaustion
f06928a fix(deploy): make APP_TIMEZONE configurable and compose env optional
eb48ec0 chore(deploy): add compose env example and ignore local secrets
56829c2 chore(qa): give PHPStan a stable analysis memory limit
35b6449 docs: clarify table ownership and local deployment setup
12e81a5 chore(contract): validate OpenAPI with Redocly and document it
a3f3c16 test(contract): clarify documented route exclusions
```

Two small follow-up commits (`78e806e`, `07e61e8`/`56829c2`) are static-analysis/tooling corrections found by the gates, not scope changes.

## Files created or changed

### Root
- `README.md` — purpose, honest scope, prerequisites, setup, tests, directory map, docs links, demo-credential placeholder.
- `AGENTS.md` — TDD, authorization, single-writer, forward-only migrations, no money floats, ADRs, gate list.
- `.editorconfig` — UTF-8/LF/final newline; PHP 4 spaces; TS/Vue/JSON/YAML 2 spaces.
- `package.json`, `pnpm-workspace.yaml`, `pnpm-lock.yaml` — pinned `yaml@2.9.1` and `@redocly/cli@2.82.0`, scripts for all required frontend/OpenAPI gates.
- `scripts/frontend-workspace.mjs` — fails non-zero with `frontend not scaffolded yet` until `frontend/package.json` exists; runs the workspace script when it does.
- `.redocly.yaml` — recommended ruleset; explicitly disables only the two rules affected by intentionally pre-declared common responses and localhost.
- `.gitleaks.toml`, `.gitignore` update for `deploy/compose/.env`.

### Backend
- `app/Shared/Architecture/ModuleBoundaryScanner.php` — token-based scanner for imports, grouped imports, fully-qualified references, string literals, cross-module raw table access and Schema DDL.
- `app/Shared/Architecture/BoundaryFinding.php`, `BoundaryReport.php`.
- `app/Console/Commands/ArchTestCommand.php` — `arch:test`, optional `--path`/`--json`.
- `app/Console/Commands/CapabilityAuditCommand.php` — `capability:audit`, exact public allowlist, middleware/default capability lookup, summary table, warning-only negative manifest.
- `tests/Authorization/endpoint_matrix.php` — health/meta entries for extension by later tasks.
- `tests/Architecture/ModuleScannerTest.php` — 7 unit tests.
- `tests/Architecture/ArchTestCommandTest.php` — clean, synthetic violation, current tree.
- `tests/Architecture/CapabilityAuditCommandTest.php` — public routes, unguarded route failure, middleware, route default, manifest warning.
- `tests/Contract/OpenApiContractTest.php` — route/document parity, health/meta shapes, 3.1 and security schemes.
- `tests/Contract/OpenApiLintCliTest.php` — validates the real spec, malformed YAML and missing-`responses` failures.
- `tests/Integration/BackupRestoreSmokeTest.php` — shells out with temp SQLite files.
- `tests/{Architecture,Contract,Integration}/.gitkeep` — makes all PHPUnit suites runnable.
- `config/app.php` — `APP_VERSION` and `APP_TIMEZONE` configurable (defaults retain previous behavior).
- `phpstan.neon`, `phpstan-bootstrap.php` — single-process analysis and a 1G analysis-only memory limit; no runtime effect.
- `.env.example` — MySQL/Redis/S3/MinIO parity placeholders, `APP_TIMEZONE=UTC`, `SESSION_COOKIE=hrms_session`, `APP_VERSION`.

### Docs
- `docs/01-architecture.md` — five layers, module ownership table, dependency rule, allowlist policy, request-id/audit/outbox/idempotency conventions, intentional exclusions.
- `docs/adr/0001-modular-monolith.md` — Accepted ADR using the plan §15 template.
- `docs/adr/0002-platform-baseline.md` — Accepted ADR for envelope, request id, evidence services, contract/deploy baseline.
- `docs/openapi/openapi.yaml` — OpenAPI 3.1: servers, tags, cookie/CSRF schemes, Envelope/ErrorEnvelope/Health/Meta/Pagination, exact health 200 and 503 shapes, common 401/403/404/409/422/429/500.
- `README.md` and `docs/01-architecture.md` link the machine-readable table ownership map.

### Deploy / CI
- `.github/workflows/ci.yml` — PHP 8.4, composer install, Pint, PHPStan, tests, capability audit, arch test; Node/pnpm, OpenAPI lint + Redocly; conditional frontend gates; Compose syntax.
- `.github/workflows/security.yml` — composer audit, gitleaks, dependency review.
- `deploy/compose/docker-compose.yml` — Nginx, php-app, php-worker, php-scheduler, MySQL 8.4, Redis 7, MinIO; healthchecks, restart policies, named volumes, non-root app image, placeholder secrets.
- `deploy/compose/.env.example` — local Compose placeholders; real `.env` ignored.
- `deploy/docker/php.Dockerfile` — multi-stage Composer + PHP 8.4-FPM Alpine, pdo_mysql, bcmath, intl, mbstring, opcache, pcntl, non-root, healthcheck.
- `deploy/docker/nginx.conf`, `deploy/docker/php.ini`, `deploy/docker/.dockerignore`.
- `deploy/scripts/openapi-lint.mjs` — pinned `yaml` parser; fails malformed YAML, missing paths/responses, bad statuses, unresolved refs, missing required schemas.
- `deploy/scripts/backup_restore_smoke.sh` — `set -euo pipefail`, copy, SHA-256, restore, compare, PASS/FAIL, temp cleanup.

## Explicit boundary decision

The brief's fuzzy allowlist is resolved concretely:

- default: modules may import only `App\Shared\*`, their own module, and other modules' `Contracts\*`;
- `Platform\Models\AuditEvent`/`OutboxEvent` are **not** allowlisted. They currently appear only in Platform code and tests;
- `ModuleBoundaryScanner::EXPLICIT_ALLOWLIST` is a `FQCN => reason` constant, currently empty;
- `docs/01-architecture.md` documents the exception procedure (prefer a Platform Contract first; otherwise add exactly one FQCN plus a dated reason and table row);
- Platform is forbidden from importing any business module, including business `Contracts`;
- cross-module raw `DB::table` access is a violation for owned tables and a warning for unknown tables; `Schema::*` in `app/Modules` is a violation.

## TDD evidence

Each behavior slice was written test-first and watched fail for the expected reason:

1. `ModuleScannerTest` / `ArchTestCommandTest` — initially `Class ModuleBoundaryScanner not found`, then command missing; now 10 pass.
2. `CapabilityAuditCommandTest` — initially `Command "capability:audit" does not exist`; then output-format failures fixed in production output; now 5 pass.
3. `OpenApiContractTest` / `OpenApiLintCliTest` — initially missing spec/paths, then YAML key parsing, then relative-path errors; now 6 pass.
4. `BackupRestoreSmokeTest` — initially missing script; now 2 pass (plus explicit missing-DB failure verified manually).

Config/docs/deploy files are not behavior-bearing and were validated by gates.

## Exact final command results (HEAD `a3f3c16`)

Run from `backend/` with `/d/code/ai/factoryHR/.tools/php` prepended to `PATH`:

| Command | Exit | Result |
| --- | --- | --- |
| `php artisan test` | 0 | **51 passed (279 assertions)** in ~7.0s |
| `vendor/bin/pint --test` | 0 | PASS, 75 files |
| `vendor/bin/phpstan analyse` | 0 | `[OK] No errors` (60 files) |
| `php artisan capability:audit` | 0 | health/meta PUBLIC, `Capability audit OK.` |
| `php artisan arch:test` | 0 | 14 files scanned, `Module boundaries OK.` |

Root:

| Command | Exit | Result |
| --- | --- | --- |
| `node deploy/scripts/openapi-lint.mjs` | 0 | `OK (2 paths, 2 operations, 3.1.0)` |
| `pnpm openapi:validate` | 0 | Redocly: API description valid |
| `docker compose -f deploy/compose/docker-compose.yml config --quiet` | 0 | syntax valid |
| `bash deploy/scripts/backup_restore_smoke.sh` | 0 | PASS, matching SHA-256 on local SQLite |
| `php artisan migrate:fresh --seed --force` | 0 | all 7 migrations + seed succeeded |

Suite split: Unit 13, Feature 15, Contract 6, Integration 2, Architecture 15 = 51.

Frontend guards (`pnpm typecheck`, `lint`, `test:unit`, `test:e2e`, `openapi:generate`) each exit 1 with `frontend not scaffolded yet: missing .../frontend/package.json`, which is the required behavior before Task 8.

## Decisions and deviations

1. **OpenAPI lint is dependency-light plus Redocly.** The brief offered `@redocly/cli` or a structural validator. `yaml` + a structural validator is the blocking gate because it deterministically catches malformed YAML and missing `responses` with no network; Redocly is pinned as an additional `openapi:validate` check in CI. Both are green.
2. **Scanner is token-based, not regex-based.** This correctly handles grouped imports and avoids matching comments/strings accidentally; string literals that contain `App\Modules\...` are intentionally checked as references.
3. **PHPStan memory.** This machine's PHP CLI has a 128M limit; Laravel/Larastan cold analysis exceeds it. `phpstan-bootstrap.php` raises the limit for analysis only and `parallel.maximumNumberOfProcesses: 1` avoids worker crashes. This does not affect application runtime. On CI, `--memory-limit=1G` is also passed.
4. **Compose uses development placeholder credentials** (`change-me`, `change-me-root`) only so `docker compose config` is reproducible without secrets. A git-ignored `deploy/compose/.env` overrides them; no real secrets are present.
5. **No changes to the Platform core.** The passing modules, migrations, response envelope, request-id, audit/outbox/idempotency and health/meta behavior were left intact.
6. **PHP 8.4 vs composer.json `^8.3`.** The locked framework and CI use PHP 8.4; the `^8.3` constraint was pre-existing and was not tightened because it would be an unrelated dependency change.

## Known gaps / concerns

- **Not a production deployment claim.** Compose is a syntax-validated skeleton; no live stack was started. MySQL PITR, HA, TLS termination, KMS, a real broker, MinIO bucket provisioning and object lifecycle are later deployment concerns.
- **Outbox dispatcher remains the baseline log adapter.** A broker adapter, retry/dead-letter worker and outbox drain command are future work.
- **Negative-test manifest is warning-only by design.** It currently contains health/meta. Later tasks must add the four mandatory denial cases per guarded endpoint and ideally promote the warning to a failure.
- **Frontend does not exist.** All five frontend-dependent scripts correctly fail; Task 8 must scaffold `frontend/package.json` with matching script names.
- **The scanner is static and pragmatic.** It catches imports, FQ references and literal `DB::table`/`Schema` use. Dynamic table names and indirect container lookups are warnings or outside its scope; `--json` output is available for future CI dashboards.
- **No live `docker build` was run** (Docker Compose config was the required check); the PHP image is therefore syntax/reviewed but not build-verified in this session.
- **PHPStan's first cold run on this Windows host can transiently fail on Windows temp-cache rename** after analysis completes (exit 1 with `Could not write ... resultCache.php`); an immediate rerun is green. The configured bootstrap/process settings make the analysis itself pass reliably; CI on Linux does not use the Windows temp rename path. Evidence includes both the transient failure and the clean reruns.

## Verification notes

- All commits are in `chore/task-1b-baseline` only; main and other worktrees were untouched.
- No `.env`, `vendor/`, `node_modules/`, SQLite DB or generated secrets are tracked (`git ls-files` check clean).
- Worktree is clean at HEAD (`git status --short --branch` shows no changes).
- `docs/01-architecture.md`, ADR files and OpenAPI are new files; approved solution/plan markdown were not modified.
