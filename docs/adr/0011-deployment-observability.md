# ADR-0011: Deployment, backup and observability baseline

- **Status:** Accepted — 2026-11-23
- **Decision IDs:** D-07 (evidence/contracts), D-25 (deployment baseline); supports D-05/D-07/D-08
- **Supersedes:** none
- **Review trigger:** production incident tracing cannot follow a request end-to-end, outbox volume requires a broker adapter, or session/cookie requirements change under ADR-0013 or the outbox needs a broker per ADR-0008

## Context and trigger

The Platform module owns cross-cutting evidence and infrastructure: request correlation, the response envelope, exception mapping, audit, outbox, idempotency and file metadata. These choices are shared by every later module, so changing them after business endpoints exist would touch all contracts, tests and operational runbooks.

The repository must be honest about its scope: this is a reference implementation of the approved V1.0 architecture, not a finished 30-week rollout. The baseline needs enough platform behavior to make later vertical slices safe and testable without pretending that IAM, payroll, HA, KMS or external integrations already exist.

## Decision

Establish the following Platform baseline:

1. **One envelope** — `{code, message, data, request_id}` for success and failure; stable `ErrorCode` enum; `ExceptionRenderer` is the only exception-to-envelope mapper and never leaks internals.
2. **Request correlation** — `RequestId` middleware accepts a constrained inbound `X-Request-Id` or generates a ULID; the id is in the response header, every envelope, log context and `RequestContext`.
3. **Public surface** — only `GET /v1/health` and `GET /v1/meta`; health returns 503 for a database failure and 200 `degraded` for cache/storage failures.
4. **Evidence** — append-only `audit_events`; transactional `outbox_events` with sensitive-key rejection; `IdempotencyService` for replay-safe commands; private `file_objects` metadata. Business modules use Platform Contracts, not Platform internals.
5. **Contract and gates** — OpenAPI 3.1 at `docs/openapi/openapi.yaml`; OpenAPI lint, Laravel contract tests, `capability:audit` and `arch:test` run in CI.
6. **Deployment skeleton** — Compose stack with Nginx, PHP-FPM app/worker/scheduler, MySQL 8.4, Redis 7 and MinIO; healthchecks and no real secrets. This is a development/reference skeleton, not proof of a production HA deployment.
7. **Local defaults** — SQLite for development and tests, UTC process timezone, MySQL/Redis/S3 settings in `.env.example` for parity.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Per-endpoint ad-hoc JSON responses | Inconsistent errors and request ids; impossible to generate a stable frontend client or contract tests. |
| Framework default HTML exception pages | Leaks stack traces and mixed formats; violates the approved error semantics and enumeration protections. |
| Audit/logging only through application logs | Logs are not queryable evidence and cannot guarantee append-only history or transaction atomicity with outbox. |
| Publish outbox synchronously inside the business transaction | External side effects can fail or duplicate and would make local business state depend on a broker. The dispatcher runs after commit in later tasks. |
| Start with MySQL-only local setup | Slows contributor setup and makes fast PHPUnit runs harder; SQLite is adequate for the current Platform tests, with MySQL covered by Compose and CI. |

## Consequences

Positive:

- every later module inherits a consistent contract, correlation id and error model;
- audit/outbox/idempotency are available before business writes exist;
- the OpenAPI and architecture gates detect drift early;
- the baseline is runnable locally without Docker.

Negative / costs:

- the envelope and exception mapping are now a compatibility surface;
- Platform service contracts must be versioned carefully as modules arrive;
- the log-based outbox dispatcher is not a production broker; a real adapter and retry/dead-letter runbook remain future work;
- SQLite and MySQL differences must be tested as models grow.

Security and privacy: no secrets are committed; PII is rejected from outbox payloads; audit rows are append-only; file metadata hides storage keys; public endpoints are limited to health/meta. Production IdP, KMS, encryption at rest, DLP and penetration testing are organizational gates not claimed by this ADR.

## Validation

- `php artisan test` — request id, envelope, error mapping, health/meta, audit append-only, outbox, idempotency, OpenAPI contract, architecture and backup smoke tests.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`.
- `php artisan capability:audit` and `php artisan arch:test`.
- `node deploy/scripts/openapi-lint.mjs`.
- `docker compose config` for the deployment skeleton (syntax, not a live stack).

Evidence lives in `.superpowers/sdd/artifacts/task-1b-report.md`.

## Rollback and review

Endpoint contracts can be extended additively; removing envelope fields or error codes requires a new API version and ADR. The outbox dispatcher can be replaced behind `OutboxDispatcher` without touching module code. Re-review when adding a real broker, when session/CSRF rules change under ADR-0013, or before any production deployment claim. Owner: technical lead / platform owner.
