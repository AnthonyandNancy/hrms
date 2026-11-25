# HRMS operations guide

This guide is the operator entry point for deployment, health, backups, migration
rehearsal, feature flags, retention and incident response. It describes what is
implemented on `main` and, equally important, what is not yet proven.

## 1. Current delivery status (honest scope)

| Area | Implemented here | Notes / gaps |
|---|---|---|
| Migration framework | CSV importers, staging, two-phase validate/apply, idempotent upsert, reconciliation, rollback plan | Target tables exist on `main`; rehearsals still use an explicitly labelled SHADOW target until a real extract is supplied. |
| Cutover/rollback/hypercare | Runbooks, drills | Staging/production rehearsal with real MySQL and real extracts is still required before a production claim. |
| Deployment | Compose topology, Dockerfiles, env template, deploy/smoke scripts | `docker compose config` passes and the stack has been built and started on a real host; `smoke.sh` and `deploy_staging.sh` are configuration-reviewed but have not run against a real staging host. |
| Health | `/v1/health` legacy, `/v1/health/live`, `/v1/health/ready` | Readiness checks DB, cache and storage. Liveness touches no dependency. |
| Feature flags | Five server-enforced flags, audit trail, CLI, API | Defaults off. Route middleware returns stable 404 when disabled. |
| Retention | Preview, dry-run purge, `--confirm`, legal holds, audit | Class definitions exist for payroll/contracts/accounting/audit/annual reports; unknown tables still make purge fail closed. |
| Logging | Structured request line, redaction tap, tests | Redacts known keys plus ID/email/mobile patterns; review any new free-text field. |
| Demo | `demo:seed`, `demo:reset`, `demo:verify` | Coherent synthetic dataset, opt-in only; the default install path creates zero business rows. |
| Monitoring | Prometheus config, 12 alert rules, Grafana JSON | Configuration only: the app exposes no `/metrics` endpoint yet, so PHP scrape targets are inert. Health probes work. |

## 2. Deployment topology

```
internet -> TLS terminator / load balancer
          -> frontend (nginx static shell, proxies /v1)
             -> php-app (PHP-FPM)
php-worker, php-scheduler (same image, different command)
mysql 8.4 | redis 7.4 | minio (private compose network, named volumes)
```

Services and healthchecks: `deploy/compose/docker-compose.yml`.
Environment template: `deploy/compose/.env.example` (placeholders only).

### Compose commands

```bash
# Always validate before deploying.
docker compose \
  --env-file deploy/compose/.env \
  -f deploy/compose/docker-compose.yml config --quiet

# Start / stop
docker compose --env-file deploy/compose/.env -f deploy/compose/docker-compose.yml up -d
docker compose --env-file deploy/compose/.env -f deploy/compose/docker-compose.yml down
```

The stack uses `read_only: true`, `no-new-privileges`, tmpfs for writable
framework directories and named volumes for database/object data. Run it as a
dedicated deployment user; do not mount the repository read-write into app
containers.

### Secrets

Never commit `deploy/compose/.env`. Inject secrets from the platform secret
manager (Vault, AWS Secrets Manager, GCP Secret Manager, SOPS with KMS) into the
process environment or a root-owned env file with mode `0600`.

Required secret values:

| Variable | Purpose |
|---|---|
| `APP_KEY` | Laravel encryption (32-byte base64) |
| `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` | MySQL credentials |
| `REDIS_PASSWORD` | Redis auth when not isolated |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | MinIO/S3 credentials |
| `MINIO_ROOT_USER`, `MINIO_ROOT_PASSWORD` | MinIO admin |

Rotation: generate new value -> update secret manager -> rolling restart of
php-app/php-worker/php-scheduler -> verify readiness and outbox -> revoke old
value. `APP_KEY` rotation requires re-encryption of existing ciphertext; do not
rotate it ad hoc.

## 3. Deploy and smoke

```bash
# Idempotent staging deploy: preflight, build, remote up, readiness, cache warm, smoke.
STAGING_HOST=... STAGING_USER=... STAGING_PATH=... \
  deploy/scripts/deploy_staging.sh --env-file /secure/hrms.env

# Dry run without side effects.
deploy/scripts/deploy_staging.sh --env-file /secure/hrms.env --dry-run

# Point smoke at any environment.
deploy/scripts/smoke.sh --base-url https://staging.example.test
```

`deploy_staging.sh` refuses `.env.example`, rejects known placeholder secrets,
locks concurrent deploys remotely, waits for readiness (not liveness), warms
config/route caches, restarts workers and runs smoke. On smoke failure it stops
the new stack and exits non-zero.

### Smoke coverage

1. `/v1/health/live` 200 `OK` (no dependencies)
2. `/v1/health/ready` 200 `OK` (DB/cache/storage)
3. `/v1/health` 200 legacy summary
4. `/v1/meta` stable fields identical on repeat
5. unauthenticated `/v1/platform/features` -> 401/403 stable code
6. supplied password is never echoed by `/v1/meta`
7. feature route remains denied without capability

## 4. Health and readiness

| Endpoint | Purpose | Failure semantics |
|---|---|---|
| `/v1/health/live` | Container liveness | Always 200 unless the process itself is dead; never touches DB/cache/storage |
| `/v1/health/ready` | Traffic readiness | 503 `SERVICE_UNAVAILABLE` when DB, cache or storage fails |
| `/v1/health` | Legacy summary | 200 with `data.status=degraded` for non-fatal dependency trouble (compatibility) |

Use liveness for restart decisions and readiness for load-balancer membership.
A failing readiness must drain traffic, not restart the container repeatedly.

## 5. Feature flags

Flags are server-owned; clients cannot assert them.

| Flag | Default | Typical owner |
|---|---|---|
| `payroll_enabled` | off | Payroll Lead |
| `time_enabled` | off | HR Operations |
| `self_service_enabled` | off | HR Operations |
| `recruitment_enabled` | off | Talent Acquisition |
| `export_enabled` | off | Data Protection Officer |

```bash
php artisan feature:set time_enabled on --tenant=1 --reason="cutover wave 2 approved" --actor=42
php artisan feature:set payroll_enabled off --tenant=1 --reason="incident INC-104 rollback"
```

Every change and every blocked request is written to `audit_events`. The HTTP
API is `PUT /v1/platform/features/{flag}` behind
`capability:platform.feature.manage`; the capability middleware fails closed when
IAM has not bound an authorizer, so no client header can bypass it.

## 6. Migration operations

### Commands

```bash
php artisan migrate:domain --domain=people --file=/secure/people.csv --mode=validate --mapping=/secure/mapping.csv
php artisan migrate:domain --domain=people --file=/secure/people.csv --mode=apply    --mapping=/secure/mapping.csv
php artisan migrate:reconcile --run=42 --json --output=/secure/reports/run-42.json
php artisan migrate:rehearsal --round=1 --dataset=demo --output=/secure/reports/rehearsal-1.json
php artisan migrate:rollback-plan --run=42 --output=/secure/reports/rollback-42.json
php artisan ops:demo-state --tenant=1 --json
```

Directories: `backend/database/migration-fixtures/known-good`,
`broken`, `mapping`. All fixtures are synthetic.

### Guarantees

- Whole-file validation happens before any target write.
- Any ERROR issue blocks apply (`VALIDATION_FAILED`).
- Validation/dry-run never write target rows.
- Apply is transactional per domain and idempotent by natural key.
- Every target row carries `source_type` and `source_id=<stg id>`.
- Reconciliation compares staged count and sums with target metrics; any
  difference persists `RECONCILIATION_*` issues and exits non-zero.
- Shadow rehearsal rows use `SHADOW:` domain labels and are never reported as
  live business data.

### Local rehearsal

```bash
deploy/scripts/migration_rehearsal.sh --round 1 --dataset demo
```

Creates an isolated SQLite database under `build/ops/`, migrates, runs
validate+apply+reconcile for all six domains against SHADOW, prints PASS/FAIL
and writes a JSON report with checksum. Exit is non-zero on any mismatch.

### Rollback preparation

`migrate:rollback-plan` writes a JSON plan with scoped DELETE statements, a
SHA-256 `plan_digest`, irreversible-item notes and mandatory preconditions. It
**never executes destructive SQL**. `--execute --confirm-destructive` only marks
the plan as reviewed. Execution belongs to `docs/runbooks/rollback.md` under DBA
supervision.

## 7. Backup and restore

```bash
deploy/scripts/backup_restore_smoke.sh        # isolated SQLite proof
deploy/scripts/rollback_drill.sh               # snapshot -> bad release -> restore
```

The SQLite drills prove the mechanics (consistent backup, mutation, restore,
row-count equality, failed migration not recorded). Production must use MySQL
snapshots/PITR plus object-storage versioning; those managed-service procedures
must be rehearsed on the real platform before go-live.

Acceptance for a restore is **logical equality** (table row counts and checksums
of critical business columns). A raw file checksum may differ after SQLite page
reallocation; the scripts report this explicitly.

## 8. Retention and legal hold

```bash
php artisan retention:preview --all --tenant=1
php artisan retention:preview --class=payroll_payment_records --tenant=1 --as-of=2026-11-23
php artisan retention:purge --class=payroll_payment_records --tenant=1          # dry-run
php artisan retention:purge --class=payroll_payment_records --tenant=1 --confirm
```

- Purge is dry-run unless `--confirm`.
- Active legal holds always win over expiry.
- Every purge writes a `retention_purge_runs` row and an `audit_events` record.
- Permanent classes (annual financial reports) are never purged.
- Classes whose tables are owned by parallel modules fail closed with a clear
  message instead of silently reporting zero.

Create legal holds in `legal_holds` with `retention_class`, `subject_type`
(table or `*`), `subject_id` (row id or `*`), reason, `starts_at`, optional
`ends_at`; release by setting `released_at` (never delete the hold record).

## 9. Logging and privacy

Every request emits one structured `http.request` line with `request_id`,
`tenant_id`, `user_id`, route, method, path, status and `duration_ms`. Request
and response bodies, query strings and headers are never logged.

`RedactingLogTap` applies to the `single`, `daily` and `stderr` channels and
redacts sensitive keys at any nesting depth plus ID-number, email and mobile
patterns. Tests prove a password, token and national ID never reach a log file.
Add new sensitive field names to `SensitiveDataRedactor::SENSITIVE_KEYS`.
Logs ship to stdout/stderr under compose (`LOG_CHANNEL=stderr`) for the platform
collector; retention of security logs is defined in `RetentionClass`.

## 10. Monitoring and alerts

Files:

- `deploy/monitoring/prometheus.yml` — scrape config (app, worker, MySQL, Redis,
  blackbox probes);
- `deploy/monitoring/hrms-alerts.yml` — 12 alerts: availability, readiness,
  5xx rate, p95 latency, queue depth/age, outbox lag, workflow effect failures,
  stuck payroll run, stale backup, reconciliation failure, feature-flag spike;
- `deploy/monitoring/grafana/hrms-dashboard.json` — operations dashboard.

The metric families (`http_requests_total`, `queue_size`, `outbox_pending_events`,
`backup_last_success_timestamp_seconds`, etc.) must be exported by the metrics
sidecar/service before alerts fire. **As of this baseline the application does
not expose a `/metrics` endpoint**, so every `hrms-php-app` / `hrms-php-worker`
scrape target in `prometheus.yml` resolves to nothing. Until that exporter is
wired, these files are reviewed configuration only and must not be presented as
live coverage. The blackbox `/v1/health/ready` probes do work as written.

## 11. Incident quick reference

| Symptom | First checks | Runbook |
|---|---|---|
| Readiness 503 | DB reachable, Redis auth, storage/S3 credentials, migrations | `docs/runbooks/rollback.md` §3 |
| 5xx spike after deploy | release id, error logs by `request_id`, readiness, recent migration | `docs/runbooks/rollback.md` |
| Queue/outbox backlog | worker health, failed jobs, Redis, downstream consumer | Hypercare §2 |
| Payroll discrepancy | run state, reconciliation totals, correction policy | Rollback §1, Payroll sign-off |
| Migration mismatch | `migration_issues`, reconciliation JSON, staged counts | Cutover §3 |
| Backup stale | exporter, cron/scheduler, object store permissions | Hypercare §5 |
| Suspected data leak | rotate credentials, disable flags, preserve logs | Rollback §3 + Security |

## 12. Known limitations

1. No real customer extract has been supplied, so rehearsals still run against
   the explicitly labelled SHADOW target.
2. `deploy_staging.sh` and `smoke.sh` are configuration-reviewed; they have not
   been executed against a real staging host.
3. Metric exporter wiring is not part of this repository; the Prometheus and
   alert rules are reviewed configuration, not live monitoring.
4. A real IdP/OIDC integration, bank and tax filing, payslip PDF, HA, KMS,
   MySQL PITR and the S-01–S-08 sign-offs remain organizational gates.
