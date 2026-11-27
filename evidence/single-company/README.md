# Single-company rehearsal evidence

Captured against the Compose **MySQL 8.4.13** service on 2026-11-26, on an
isolated `factoryhr_rehearsal` database cloned from the then-current live
database (a genuine pre-cutover state: 171 legacy column instances).

| file | what it is |
| --- | --- |
| `preflight-mysql.json` | inventory before the cutover: **156 tables, 171 legacy column instances**, `company_profile=1`, `data_disposition=REBUILD` |
| `post-cutover-mysql.json` | inventory after `migrate:fresh`: **155 tables, 0 legacy columns**, `legal_entities` absent, `company_profile=1` |

Restore check: the post-cutover database was dumped and restored into a fresh
`factoryhr_restore_check` schema, which came back with **155 tables and 0 legacy
columns**.

The migration suite also ran on this MySQL instance:
`SingleCompanyMigrationTest` → `OK (7 tests, 639 assertions)`.

A defect found here and fixed: `company:preflight` called `Schema::getTables()`
without a schema argument, so on MySQL it read the whole server and counted
co-located databases (a restore copy) as if they were the connected one — which
is why an early capture listed every table twice. It now scopes to the connected
schema on MySQL and lets SQLite use its own `main` schema.

Commands:

```bash
COMPOSE_PROJECT_NAME=factoryhr \
MYSQL_DATABASE=factoryhr_rehearsal \
MYSQL_ROOT_PASSWORD='<test password>' \
BACKUP_DIR=evidence/single-company \
bash deploy/scripts/single_company_rehearsal.sh
```

```bash
# post-cutover restore drill
mysqldump ... factoryhr_rehearsal > /tmp/rh.sql
mysql -uroot -e 'DROP DATABASE IF EXISTS factoryhr_restore_check; CREATE DATABASE factoryhr_restore_check;'
mysql -uroot factoryhr_restore_check < /tmp/rh.sql
# 155 tables, 0 legacy columns
```
