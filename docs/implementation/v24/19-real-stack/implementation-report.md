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

## T19-MCP-003 and wildcard authorization fix

- Real scheme/runs/user fixtures and custom PAYROLL_RUN role created.
- UI role scope saved `payroll.run.read / PAYROLL_RUN / 1`; real MCP showed
  run #1 allowed and run #2 forbidden.
- Found and fixed an authorization defect: installer COMPANY wildcard did not
  match PAYROLL_RUN-guarded routes. Regression added to
  `SuperuserFullAuthorityTest`; full gate passed.
- Real member API after the fix: **201**. Real user API parity: run #1 **200**,
  run #2 **403**.
- The runtime validation used a hot-patched same-release backend; rebuilding the
  final backend image is still required before release.

Remaining T19: real previous-release upgrade package, final backend image,
deployment-traffic restore drill, TLS/secret-manager cutover and final GO.


## T19-MCP-004 role assignment and overlap repair

- Real user-management UI assigned AUDITOR to `t19.payroll`; the role column
  showed AUDITOR + PAYROLL_SPECIALIST_T19.
- The effective projection at `auth_version=5` contained exactly those two
  role codes. Older `user_roles` rows are immutable snapshot generations, not
  duplicate active grants.
- A real overlap probe found a 500 unique-constraint failure when adding
  TRAINING_ADMIN. `assignRole` now locks and merges an existing binding at the
  same principal/capability/anchor/window/version and widens only the field
  profile.
- Regression added; full gate passed (22 gates, 248.3s). Real retry returned
  201; effective `auth_version=6` has one COMPANY binding each for
  `org.tree.read` and `people.employee.read`.

Remaining T19: real previous-release upgrade package, final backend image,
deployment-traffic restore drill, TLS/secret-manager cutover and final GO.


## Image build blocker

- `factoryhr/php-app:v24-t19c` exists and both wildcard and role-merge fixes
  were validated in the running `factoryhr-t19` containers.
- A clean `v24-t19d` rebuild was attempted from HEAD `3f36f41`, but the Alpine
  package layer stalled in the gcc installation stage for more than ten minutes
  and was stopped. The runtime containers remain healthy with db/cache/storage
  checks true.
- Therefore the current validation is explicitly runtime-hotpatched, not a
  clean release image. A final clean image build must be repeated before GO.


## v24-t19d overlay image smoke

- Built a fast reproducible overlay on `v24-t19c` containing the two committed
  backend fixes; both files passed `php -l`.
- Deployed php-app/worker/scheduler on `factoryhr/php-app:v24-t19d` and restarted
  nginx to refresh upstream DNS.
- Health 200 (db/cache/storage true); overlap role API 201; effective
  `org.tree.read` and `people.employee.read` each have one COMPANY binding;
  run #1 API 200 and run #2 403.
- Evidence: `t19d-smoke/`.
- Boundary: this is an overlay image, not a fresh full Dockerfile build. The
  latter remains required before GO.


## Same-database rollback/forward deployment

- Baseline DB: users=2, employments=0, payroll_runs=2, cases=0,
  migrations=99.
- Deployed `factoryhr/php-app:v24-t19c`; health 200.
- Deployed `factoryhr/php-app:v24-t19d`; health 200; DB counts unchanged.
- Post-deploy synthetic payroll login 200; run #1 200 and run #2 403.
- Evidence: `upgrade-rollback/`.
- The runtime-code diff from the v24-t19c base is exactly the two backend
  files overlaid in v24-t19d plus two frontend role-anchor files already in the
  nginx v24-t19b image. This is an incremental release path; a fresh full
  Dockerfile build remains outstanding.
