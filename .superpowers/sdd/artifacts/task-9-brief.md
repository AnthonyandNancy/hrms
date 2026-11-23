# Task 9 — Migration, cutover tooling, deployment and operations assets

## Context

All application modules exist. Read `.superpowers/sdd/artifacts/interface-freeze.md`.

Authoritative sources: plan §4.10, §8, §12; solution §15.3–15.4; UAT-13; evidence package §13.2.

This task builds the operational proof: import pipeline with reconciliation, cutover/rollback runbooks, health/backup/monitoring assets, and demo data that makes the whole system walkable. It must not claim production readiness that cannot be tested here.

## Migration pipeline

Create `backend/app/Modules/Platform/Migration/` with:
- `csv` importers for: organizations, people/employments, assignments, contracts/terms, leave opening balances, payroll YTD.
- Staging tables (`stg_*`) with raw row hash and source row number; every imported target row stores `source_type='MIGRATION'`, `source_id=<stg id>`.
- Two-phase: `validate` (profile, dedupe, orphan, mapping, type/date checks) and `apply` (idempotent upsert/append). Re-running apply must not duplicate.
- `migration_runs`: id, tenant_id, domain, source_file_hash, mode (`DRY_RUN|APPLY`), status, rows_read/valid/created/updated/skipped/failed, started_at, finished_at, report_json.
- `migration_issues`: run_id, stg_id, severity, code, message, source_row_number, resolved_at.
- Reconciliation: per domain compare source count/sum to target count/sum; produce JSON + human table; any unexplained difference => exit non-zero.
- Mapping file support: CSV mapping old org codes -> new org ids, old employee numbers -> new employment ids.

Commands:
- `php artisan migrate:domain --domain=organizations --file=... --mode=validate|apply`
- `php artisan migrate:domain --domain=people ...`
- `php artisan migrate:reconcile --run=`
- `php artisan migrate:rehearsal --round=1 --dataset=demo`
- `php artisan migrate:rollback-plan --run=` (produces SQL/JSON plan; does not execute destructive rollback without explicit flag + confirmation)

## Data domains and rules

- Organizations: code, name, parent_code, legal_entity_code, type, valid_from/to; detect cycles; build closure; orphan parent => issue; duplicate code => issue.
- People/employments: person key (id type + number), legal name, birth date, employee_no, legal entity, hire date, status, termination date. Deduplicate by id hash; never invent IDs; same person multiple employments allowed.
- Assignments: employment_no, org_code, position_code, manager_employee_no, valid_from/to, is_primary. Overlap detection for primary; manager resolution after all rows loaded (two-pass).
- Contracts/terms: employment_no, version, start/end, salary scheme code, probation end. Version conflicts => issue.
- Leave opening: employment_no, leave_type, quantity_minutes, expiry_date. Create GRANT opening entries with `source_type=MIGRATION_OPENING`; balance reconciliation.
- Payroll YTD: employment_no, tax_year, taxable, tax_withheld, SI, HF, gross, net. Import as opening YTD rows; reconcile totals; never create fake historical runs.
Include synthetic datasets under `backend/database/migration-fixtures/` with a known-good set and deliberately broken set (duplicate employee, orphan org, overlapping primary, bad date, negative leave). Tests must use both.

## Cutover and rollback

- `docs/runbooks/cutover.md`: preconditions, freeze window, ordered domain waves (core HR -> time/leave -> payroll -> talent/self-service/reporting), go/no-go checklist, communication, verification queries, rollback points.
- `docs/runbooks/rollback.md`: triggers (Sev1, payroll diff over threshold, auth leak, reconciliation failure), decision authority, stop-writes, snapshot/PITR, feature flags, DNS/routing switch, post-rollback reconciliation of irreversible items.
- `docs/runbooks/hypercare.md`: 90-day metrics, Sev definitions, daily reconciliation, exit criteria.
- Feature flags table/config: `payroll_enabled`, `time_enabled`, `self_service_enabled`, `recruitment_enabled`, `export_enabled`; server-enforced, default off for risky domains; tests for disabled => 404/503 with stable code.
- `deploy/scripts/migration_rehearsal.sh`: runs validate+apply+reconcile against demo fixture and prints PASS/FAIL; exits non-zero on mismatch.
- `deploy/scripts/rollback_drill.sh`: creates snapshot of SQLite demo DB, simulates a failed migration, restores, verifies checksum and row counts, prints elapsed time and PASS/FAIL.
- `deploy/scripts/backup_restore_smoke.sh` (existing) must pass and be exercised by the drill.

## Deployment and operations

- Complete `deploy/compose/docker-compose.yml` for nginx, php-app, php-worker, php-scheduler, mysql 8.4, redis 7, minio, frontend static server; healthchecks, restart policies, resource limits, named volumes, non-root where possible.
- `deploy/docker/` Dockerfiles for PHP-FPM and frontend build; `.dockerignore` files.
- `deploy/compose/.env.example` with no secrets; document secret injection.
- `deploy/scripts/deploy_staging.sh` — idempotent: pull, build, migrate, cache warm, health check, rollback on failure.
- `deploy/scripts/smoke.sh` — health, login, one authorized read, one denied read, one idempotent command.
- `deploy/monitoring/` — Prometheus scrape config example, alert rules for error rate, latency, queue depth, outbox lag, effect failures, payroll run stuck, backup age; Grafana dashboard JSON optional but provide at least alert rules.
- Structured logs with request_id, tenant_id, user_id, route, status, duration; no PII. Log redaction test.
- Health endpoints differentiated: liveness `/v1/health/live` (no dependency), readiness `/v1/health/ready` (DB/cache/storage), legacy `/v1/health` summary.
- Retention/legal hold: command `php artisan retention:preview --class=...`, `retention:purge --class=... --confirm` requiring legal-hold check and dry-run by default; audit every purge. Test that legal hold blocks purge and payroll/contract retention classes exist.

## Seed/demo experience

- `php artisan demo:seed` builds a coherent tenant: 1 legal entity, ~8 org units, 12 employees, managers, contracts, shifts, 2 months attendance, leave balances, 2 payroll runs (one closed, one draft), cases in various states, notices, recruitment/performance/training samples.
- `php artisan demo:reset` guarded to non-production environments only.
- Demo credentials documented in README, clearly non-production.
- A single command `php artisan demo:verify` asserts the demo dataset invariants and prints a walkthrough checklist.

## Tests

- `MigrationValidateTest`: clean fixture passes; each broken fixture produces the expected issue code and no partial target writes.
- `MigrationIdempotencyTest`: apply twice => same counts, no duplicates.
- `MigrationReconciliationTest`: counts/sums reconcile; injected mismatch fails.
- `MigrationRollbackDrillTest`: script restores checksum and row counts (may be an integration test shelling out).
- `PayrollYtdMigrationTest`, `LeaveOpeningBalanceTest`.
- `FeatureFlagTest`: disabled domain returns stable 404/503 and is audited; enabling without capability still denies.
- `RetentionTest`: legal hold blocks purge; preview shows counts; purge requires confirmation and audits.
- `HealthTest`: live independent of DB; ready 503 when DB down.
- `LogRedactionTest`: password/token/ID number never appears in logs.
- `DemoSeedTest`: seed twice idempotent; verify passes; reset refused in production.
- Compose config validation (skip gracefully if Docker daemon unavailable, but `docker compose config` must be run and result reported).

## Report

Write `.superpowers/sdd/artifacts/task-9-report.md` with files, exact command/script results, decisions, gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
