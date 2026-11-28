# T19 — real dependency stack, install, backup/restore and upgrade probe

- Status: PARTIAL / IN PROGRESS
- Date: 2026-11-28
- Build SHA: `53f588f`
- Compose project: `factoryhr-t19` (isolated from existing `factoryhr` stack)
- Release tag: `v24-t19`
- HTTP: `http://127.0.0.1:5190`

## Verified

| Item | Evidence | Result |
|---|---|---|
| MySQL | `mysql-version.log` | MySQL **8.4.13** |
| Redis | `redis-ping.log`, `redis-set/get` | PONG + SET/GET |
| MinIO | `minio-init.log`, `minio-bucket.log`, `minio-object.log` | private bucket + object put/list |
| Isolated volumes | `volumes.log` | `factoryhr-t19_*` only |
| App services | `services-final.log` | mysql/redis/minio/php-app/worker/scheduler/nginx all healthy |
| Migrations | `migrate.log`, `migration-count.txt`, `migration-files-count.txt` | 99 applied = 99 files |
| Clean install | `install.log` | installer, 1 admin, zero employees |
| Baseline counts | `baseline-counts.txt` | users=1, employments=0, payroll_runs=0, cases=0 |
| Health | `health.json` | db/cache/storage all true |
| Login | sanitized command result in report | HTTP 200 |
| Backup | `backup.log` | mysqldump + SHA-256 + byte count |
| Restore | `restore.log`, `restore-counts.txt` | restore DB row counts match |
| Upgrade probe | `upgrade-migrate.log`, `upgrade-probe-count.txt` | synthetic forward migration applied to restored DB |
| Schema evidence | `schema-only.sql.gz`, `schema-only.sha256` | sanitized no-data schema dump |

Backup SHA-256:
`2b906440c7306be7b5de7fb4d61db381bb496f3e33bce549d32fca27ccb1ce62`.

Restore counts: migrations=99, users=1, employments=0, payroll_runs=0,
cases=0, notification_deliveries=0.

## Deliberate evidence hygiene

- Full data dump and login response were not committed: they can contain password
  hashes, session identifiers and CSRF tokens.
- Only a no-data schema dump plus checksums and count logs are stored.
- All credentials in commands are synthetic local-only values and are not stored
  in tracked evidence.

## Remaining T19 work

- App-level true MySQL concurrency for idempotency/run locks (current proof is
  stack/health/backup/restore, not concurrent worker contention).
- Upgrade from a real previous release database package, not only a synthetic
  forward migration probe.
- Object upload/download/export lifecycle through application jobs at scale.
- TLS/secret-manager cutover, restore drill under deployment traffic, and final
  GO/NO-GO.

## App-level MySQL concurrency probe

- Executed two concurrent authenticated `POST /v1/roles` requests with the same
  `Idempotency-Key`.
- Result: HTTP 201 + HTTP 200, second response carried
  `Idempotency-Replayed: true`.
- DB result: one role row and one idempotency key row.
- Evidence: `concurrency/README.md` and files in that directory.
- This proves the application's idempotency/row-locking path under MySQL, not
  only a raw unique-index test.

Remaining T19: real previous-release upgrade package, deployment-traffic restore
drill, TLS/secret-manager cutover and final GO.
