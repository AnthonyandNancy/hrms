# V2.1 backup / restore and rollback drill — evidence

Date: 2026-11-27
Branch: `feat/v2.1-remediation`, commit `20ce996`
Operator: lead (run directly, not delegated)

## 1. MySQL backup / restore drill

Command:

```bash
bash deploy/scripts/mysql_backup_restore.sh
```

Result (`evidence/v21-backup/mysql-backup-restore.log`, exit 0):

```
mysql backup/restore drill
project=factoryhr container=1dce67668583df58c4b719ac11ea38ea39b026782e5b8c72f0e19620f7befd68 database=factoryhr
dump_sha256=bb8f705599a0ebbaecad419c8a888906266d9908a1c91aba02d8f4dec8f0c116 bytes=453750
tables_checked=156 mismatches=0
PASS: mysql dump restored with identical table row counts
```

What this proves:

- A real `mysqldump` of the live `factoryhr` schema was taken and its SHA-256
  recorded.
- The dump was restored into a **temporary** database in the same container and
  compared table-by-table.
- **156 tables checked, 0 row-count mismatches.**
- The source database was never written to.

## 2. Rollback drill

Command:

```bash
bash deploy/scripts/rollback_drill.sh
```

Result (`evidence/v21-backup/rollback-drill.log`, exit 0):

```
table_count: 155
Restored database passed read-only integrity and schema checks.
Restored copy unchanged after checks: PASS
Source unchanged during drill:      PASS
Elapsed: 1.629 s
Budget:  3600 s
PASS: rollback drill completed within budget
```

What this proves:

- A consistent snapshot was taken through the SQLite online-backup API with the
  source opened **read-only**.
- The snapshot was restored to a second file; SHA-256 matched.
- `PRAGMA integrity_check` and `PRAGMA foreign_key_check` passed.
- Both the restored copy and the source were unchanged after the drill.
- 1.6 s against a 3600 s budget.

## 3. Explicitly NOT executed

These are recorded so no reader mistakes this file for a full disaster-recovery
proof:

| Step | Why not executed |
| --- | --- |
| MySQL PITR (full backup + binlogs to an isolated instance, measuring RPO/RTO) | needs an isolated MySQL 8.4 instance and binlog configuration this environment does not have |
| feature-flag rollback (`feature:set <flag> off`) | changes production state and emits an audit row; must be run in the cutover environment |
| Route switch back to the legacy system at LB/DNS | requires the real cutover environment |

The operational procedure for all three is in `docs/runbooks/rollback.md`. They
are **NOT** claimed as verified.

## 4. Scope note

The MySQL drill ran against the Compose MySQL instance that is currently up. That
instance belongs to a different worktree's project (`page-auth-remediation`), but
the *schema shape* it holds is the V2.1 single-company schema (156 tables, no
tenant columns), which is what this drill needs to prove: a dump round-trips
losslessly. A full deploy-and-restore rehearsal on this branch's own stack is
Volume 4 Task 9's job and is recorded separately, or as a remaining gap if Task 9
could not be completed.
