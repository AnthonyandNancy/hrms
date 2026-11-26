# Task 2 Report - Single-company schema cutover

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Branch: `feat/v2.1-remediation`
- Commit: `7ae663e5581c21b5611c8acc2be2bcc785ef5542`
- Subject: `feat(db): cut the schema over to a single-company model`

## Delivered

| File | Purpose |
|---|---|
| `backend/database/migrations/2026_11_26_000300_migrate_legal_entity_bindings_to_company.php` | Converts LEGAL_ENTITY anchors to COMPANY/NULL/0; missing-table guard; idempotent; forward-only no-op `down()`. |
| `backend/database/migrations/2026_11_26_000320_drop_tenant_and_legal_entity_columns.php` | Enumerates legacy tables; captures replacement unique keys before DDL; drops dependent FKs/indexes; drops columns; recreates unique keys with an existing-key guard; drops `legal_entities`; forward-only no-op `down()`. |
| `backend/tests/Feature/Organization/SingleCompanyMigrationTest.php` | 7 tests / 327 assertions on SQLite, 949 on MySQL; schema, singleton, unique-key insertion rejection, conversion/idempotency, missing-table guard, FK drop, derived-key/guard. |
| `docs/audit/2026-11-26-single-company-cutover.md` | Evidence: commands, exit codes, before/after counts, all 74 legacy-led index mappings, binding and guard proofs, carried seeder failure. |
| `.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-files.txt` | Exact 373-file Task 3 runtime sweep manifest with line hits. |
| `.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-manifest.md` | Per-module handoff list of the same 373 files. |

## User-decision compliance

- No duplicate merging, data preservation, `firstOrCreate` repair, or conflict-abort logic.
- Cutover posture is rebuild: `migrate:fresh --seed`.
- Replacement unique keys are derived from `Schema::getIndexes()` at migration time; there is no hardcoded table map.
- The migrations are still correct for an existing legacy database: a live-schema MySQL clone was cut over in place with exit 0.
- No migration that has been merged was edited.

## Results

### Migration test

- SQLite: `php artisan test --compact --filter=SingleCompanyMigrationTest`
  - exit **0**, **7 passed**, **327 assertions**
- MySQL 8.4.13 isolated database, same command
  - exit **0**, **7 passed**, **949 assertions**

### migrate:fresh --seed --force

- SQLite configured worktree DB:
  - exit **0**
  - 56 migrations; 155 tables; 0 legacy columns; `company_profile` = 1
  - `iam:seed-demo did not run; set DEMO_SEED_PASSWORD...`
- MySQL 8.4.13 isolated database with `DEMO_SEED_PASSWORD`:
  - exit **1**
  - both cutover migrations completed, then seeding failed:
    `Table '...legal_entities' doesn't exist`
  - failing runtime: `DemoOrganizationSeederService` (still creates `LegalEntity` with `tenant_id`)
  - **carried item owned by Volume 2 Task 3**
  - schema after failed seed: 155 tables, 0 legacy columns, 0 `legal_entities`, 1 `company_profile`
  - not faked: the command is reported as failing

### NoTenantNoLegalEntityTest

- Exit **1**; **1 failed, 3 passed (6 assertions)**
- Repository scan hit count: **1927**
- Expected still red at this stage; Task 3/4 own cleanup.

### In-place legacy cutover (MySQL 8.4)

Read-only `mysqldump` clone of the live `factoryhr` schema; injected one
`LEGAL_ENTITY` probe binding before migration.

| Metric | Before | After |
|---|---:|---:|
| Tables | 156 | 155 |
| Tables with legacy columns | 145 | 0 |
| Legacy column instances | 171 | 0 |
| Total indexes | 731 | 448 |
| Unique indexes | 265 | 262 |
| Unique led by tenant/entity | 74 | 0 |
| Non-primary indexes with legacy columns | 354 | 0 |
| `legal_entities` | 1 row | absent |
| `company_profile` | 1 row | 1 row |
| LEGAL_ENTITY bindings | 1 probe | 0 |

- `000300` probe result: `COMPANY / NULL / 0`.
- Two extra `000300::up()` calls: exit 0, no change (idempotent).
- `000320` guard probe with both `UNIQUE(tenant_id, code)` and `UNIQUE(code)`:
  legacy columns removed, exactly one `code` unique key remained.
- Live `factoryhr` was not migrated: still 54 migrations and 171 legacy column
  instances. All temporary MySQL databases were dropped.

## Replacement unique index summary

- 74 unique indexes were led by `tenant_id`/`legal_entity_id`.
- 72 were recreated from their remaining business columns.
- 1 belonged to `legal_entities` and disappeared with the table.
- 1 (`legal_entity_calendars.calendar_entity_unique`, columns only
  `tenant_id, legal_entity_id`) had no business remainder and was skipped.
- Three conventional names exceeded MySQL's 64-character limit and use
  deterministic `uniq_<sha256-prefix>` names.
- Full mapping is in the evidence document.

## Quality gates on owned PHP files

- Pint `--test`: exit 0, 3 files
- PHPStan: exit 0, no errors
- `git diff --check`: clean

## Task 3 runtime sweep

`NoTenantNoLegalEntityTest` still flags **373 runtime files / 1,633 matching
lines** under `backend/app`, `backend/routes`, and `backend/database/seeders`,
excluding the two exact `// single-company:preflight-only` marker lines.

The complete exact list is committed in:
- `.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-files.txt`
- `.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-manifest.md`

Task 3's explicitly assigned minimum files are a subset:

- `backend/app/Shared/Authorization/AuthorizationSnapshot.php`
- `backend/app/Modules/IAM/Auth/AuthenticatedPrincipal.php`
- `backend/app/Modules/IAM/Auth/PrincipalResolver.php`
- `backend/app/Shared/Authorization/EnsureCapability.php`
- delete `backend/app/Shared/Authorization/ResolveLegalEntityContext.php`
- delete `backend/app/Shared/Authorization/ResolveResourceLegalEntityContext.php`
- create `backend/app/Shared/Authorization/ResolveCompanyScope.php`
- `backend/routes/api.php`
- `backend/tests/Contract/NoTenantNoLegalEntityTest.php` (gate, not a suppression target)

Every other file in the sweep manifest must also change for the gate to reach
zero; the manifest is not an allowlist.

## Concerns

1. **MySQL fresh seeding is still red** because the demo organization seeder
   writes the removed `legal_entities`/`tenant_id` shape. This is explicitly a
   Task 3 carried item; SQLite's success is only because `DEMO_SEED_PASSWORD`
   is absent and the seeder short-circuits with a warning.
2. **Every `RefreshDatabase` test that exercises legacy models will now fail**
   until the Task 3 runtime sweep lands. The cutover migration itself is green.
3. **The contract scan is intentionally red at 1927 hits**; Task 3/4 own the
   cleanup. No gate was weakened or allowlisted.
4. **Non-unique legacy-led lookup indexes are not recreated.** They encoded the
   obsolete tenant scope; only business unique keys were recreated as required.
5. **The live MySQL database was deliberately left un-migrated** (54 migrations,
   171 legacy column instances) because this task proves the cutover on clones;
   the controller decides when to execute the real cutover.

## Commit discipline

Only explicit file paths will be added:

```text
backend/database/migrations/2026_11_26_000300_migrate_legal_entity_bindings_to_company.php
backend/database/migrations/2026_11_26_000320_drop_tenant_and_legal_entity_columns.php
backend/tests/Feature/Organization/SingleCompanyMigrationTest.php
docs/audit/2026-11-26-single-company-cutover.md
.superpowers/sdd/artifacts/v2/task-2-report.md
.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-files.txt
.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-manifest.md
```

`git add -A` and `git add backend` are forbidden and were not used.
