# Single-company rehearsal evidence

Captured against the Compose MySQL 8.4.13 service on 2026-11-26, on an isolated
`factoryhr_rehearsal` database cloned from the then-current live database.

| file | what it is |
| --- | --- |
| `preflight-mysql.json` | inventory before the cutover: 312 tables, 342 legacy column instances, company_profile=1, disposition=REBUILD |
| `post-cutover-mysql.json` | inventory after `migrate:fresh`: 155 tables, 0 legacy columns, `legal_entities` absent, company_profile=1 |

Restore check: the post-cutover database was dumped and restored into a fresh
`factoryhr_restore_check` schema, which came back with 156 tables and 0 legacy
columns.

Command:

```bash
COMPOSE_PROJECT_NAME=factoryhr MYSQL_DATABASE=factoryhr_rehearsal MYSQL_ROOT_PASSWORD='<test password>' BACKUP_DIR=evidence/single-company bash deploy/scripts/single_company_rehearsal.sh
```
