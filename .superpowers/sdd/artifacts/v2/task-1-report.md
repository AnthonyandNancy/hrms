# Task 1 Report — 单公司迁移预检与门禁基线（Volume 2）

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Branch: `feat/v2.1-remediation`
- Commit: `5c9f8b5`
- Commit subject: `feat(org): add single-company cutover preflight`
- Scope: only the assigned Volume 2 files. No Volume 1 runtime, frontend, OpenAPI, or docs/superpowers file was modified.

## Delivered

| File | Change |
|---|---|
| `backend/app/Modules/Organization/Commands/CompanyPreflightCommand.php` | New read-only `company:preflight {--json}` command; public `buildReport(): array`; `Schema::getTables()` + `Schema::hasColumn()` only. |
| `backend/bootstrap/app.php` | Registered `CompanyPreflightCommand::class` in `->withCommands([...])`. |
| `backend/tests/Feature/Organization/SingleCompanyPreflightTest.php` | 3 tests: JSON/exit 0, driver-neutral report structure, missing `company_profile` => 0. |
| `backend/tests/Contract/NoTenantNoLegalEntityTest.php` | Added `database/seeders`, `docs/generated`, `docs/openapi/openapi.yaml`; line-level exact marker exemption; migration-directory allowlist; counterexamples. |
| `scripts/audit_single_company.mjs` | Pure `auditSingleCompany({files})` + real-repo CLI. Scans app, routes, seeders, frontend/src, docs/generated, and OpenAPI; prints `{ok, failures:[file:line]}`; exit 1 on hits. |
| `scripts/tests/audit_single_company.test.mjs` | 4 gate tests: hits fail, exact marker ignored, file/directory-wide exemption rejected, migrations-only path allowlist. |
| `package.json` | Added `"single-company:check": "node scripts/audit_single_company.mjs"`. |
| `docs/audit/2026-11-26-single-company-preflight.json` | Actual legacy-DB run output. |
| `docs/audit/2026-11-26-single-company-preflight.md` | Decision, read-only evidence, full 107-table inventory, discard volumes, binding mapping, red-gate baseline and blockers. |

## USER DECISION compliance

The implementation is a **read-only inventory**, not a conflict gate:

- No duplicate business-key detection or abort.
- No merge, dedupe, or preservation logic.
- No migration or DDL is executed by the command.
- Every report carries `data_disposition: "REBUILD"`.

The real report was generated against the legacy database at
`D:/code/ai/factoryHR/backend/database/database.sqlite` via an absolute
`DB_DATABASE` path. That database has no `company_profile` table, so the
required count is `0` (not the worktree test DB's seeded 1). Its SHA-256 before
and after the run was
`2b848886b84055c70d21a0fabd9cea35ed036a9061828c7ef1b368afcfdd0d4c`,
proving the command did not mutate it.

## Real legacy inventory

| Field | Value |
|---|---|
| connection | `sqlite` |
| data_disposition | `REBUILD` |
| company_profile_rows | 0 (table missing) |
| tables | 107 |
| tables with legacy columns | 98 |
| LEGAL_ENTITY bindings | 1 |
| total rows across tables | 345 |

Legacy-column distribution: 12 tables have both `tenant_id` and
`legal_entity_id`; 86 have only `tenant_id`; 0 have only `legal_entity_id`;
9 have neither.

The single `LEGAL_ENTITY` binding belongs to `principal_id=1` (`installer`),
capability `*`, `anchor_id=1`, purpose `CLI_SYSTEM_ADMIN`; the affected
`user_roles` row is `SYSTEM_ADMIN`. ADR-0037's target shape is
`anchor_type=COMPANY`, `anchor_id=null`, `anchor_key=0`; because old data is
discarded, the rebuilt seed supplies the real post-cutover binding.

## TDD evidence

### RED

`backend$ ../.tools/php/php.exe artisan test --compact --filter=SingleCompanyPreflightTest`

Exit 1: 3 failed; `Target class [App\Modules\Organization\Commands\CompanyPreflightCommand] does not exist.`

### GREEN

`backend$ ../.tools/php/php.exe artisan test --compact --filter=SingleCompanyPreflightTest`

Exit 0: **3 passed (964 assertions)**.

Gate fixture test:
`node --test scripts/tests/audit_single_company.test.mjs`

Exit 0: **4 tests, 4 pass, 0 fail**.

Extended contract test:
`../.tools/php/php.exe artisan test --compact --filter=NoTenantNoLegalEntityTest`

Exit 1: **1 failed, 3 passed**. The three structural tests pass; the
repository scan fails with **1927** current hits. This is the intended
Volume 2 baseline, not a regression introduced by this task.

## Real gate baseline (intentionally red)

`node scripts/audit_single_company.mjs`

- Exit code: **1**
- Hit count: **1927**
- Breakdown: `backend/app` 1629, `backend/routes` 4, `frontend/src` 189,
  `docs/openapi/openapi.yaml` 105; `backend/database/seeders` and
  `docs/generated` 0 (`docs/generated` does not currently exist).

The gate is not weakened. No file-wide or directory-wide runtime exemption
exists. The only runtime exception is two literal occurrences in
`CompanyPreflightCommand::buildReport()`, each carrying the exact marker
`// single-company:preflight-only`:
1. `->where('anchor_type', 'LEGAL_ENTITY')` for the binding count;
2. `['tenant_id', 'legal_entity_id']` for column existence probing.

`rg` confirms exactly 2 marker occurrences and the real gate reports 0 hits in
that command file. Counterexample tests prove an unmarked second line in the
same file and an unmarked sibling file are both detected.

## Verification summary

| Check | Command | Result |
|---|---|---|
| Preflight feature test | `php artisan test --compact --filter=SingleCompanyPreflightTest` | PASS, 3/3, 964 assertions |
| Gate fixture test | `node --test scripts/tests/audit_single_company.test.mjs` | PASS, 4/4 |
| Contract scan baseline | `php artisan test --compact --filter=NoTenantNoLegalEntityTest` | Expected FAIL, 1927 hits; 3/4 structural tests pass |
| Real gate | `node scripts/audit_single_company.mjs` | Expected exit 1, 1927 hits |
| Gate syntax | `node --check scripts/audit_single_company.mjs` + test file | PASS |
| PHP formatting | `pint --test` on the 3 owned PHP files | PASS (3 files) |
| PHPStan | `phpstan analyse app/.../CompanyPreflightCommand.php tests/.../SingleCompanyPreflightTest.php` | PASS |
| OpenAPI lint | `node deploy/scripts/openapi-lint.mjs` | PASS, exit 0: 251 paths / 323 operations / 3.1.0 |
| Diff whitespace | `git diff --check` on owned files | PASS |

## Key implementation decisions

1. **`buildReport()` has no arguments and no I/O beyond read queries**, so tests can call it directly and the JSON path uses the same logic as the table path.
2. **Stable ordering:** table names are sorted before reporting, making JSON snapshots diffable across runs.
3. **Zero-safe missing tables:** both `company_profile` and `authority_bindings` return 0 when absent; the legacy DB exercises the first case for real.
4. **Driver-neutral:** no `SHOW TABLES`, no vendor-specific SQL. `Schema::getTables()` works on SQLite and MySQL 8.4, and `Schema::hasColumn()` avoids metadata-query duplication.
5. **Line-level marker allowlist:** both scanners require the exact file *and* the exact marker line; the next line is still scanned. The migration directory is the only path-level exclusion, matching the brief.
6. **Gate reports aggregate JSON:** exactly one `{ok, failures}` document on stdout per run; parse errors also produce that shape. Missing optional scan roots are skipped rather than silently suppressing hits in existing files.
7. **No data adjudication:** no business-key queries, no duplicate detection, no merge/preserve language in the report except the explicit reason those rows are being discarded.

## Concerns

1. **The repository scan is intentionally red (1927 hits).** Volume 2 Tasks 3/4 own the runtime cleanup; this task only establishes the gate and records the baseline. Do not merge a change that adds exclusions to make it green early.
2. **The real-preflight source is the legacy SQLite database in the main checkout.** The worktree's own DB was freshly migrated and has 156 tables plus a seeded `company_profile`; it is not the legacy production-shape database. The Markdown report documents the source path and the old DB's missing `company_profile`.
3. **MySQL 8.4 rehearsal is NOT RUN in this task.** The command is driver-neutral by construction and covered on SQLite, but the real MySQL path belongs to Volume 2 Task 2 and the later rehearsal task; no MySQL claim is made.
4. **`docs/generated` currently does not exist.** Both scanners treat it as an optional root; when Volume 4 generates contracts there, it is already in scope. The current `frontend/src/generated/api.ts` is covered under `frontend/src`.
5. **The contract test's target scan uses configured test roots and will remain red until cleanup.** This is consistent with the master plan's Volume 2 exit condition; the test's structural counterexamples are green.

## Evidence files

- `docs/audit/2026-11-26-single-company-preflight.json` (real legacy DB output)
- `docs/audit/2026-11-26-single-company-preflight.md` (inventory, decision, baseline)
- `backend/tests/Feature/Organization/SingleCompanyPreflightTest.php`
- `scripts/tests/audit_single_company.test.mjs`

## Commit

```text
5c9f8b5 feat(org): add single-company cutover preflight
```

Staged exactly the 9 assigned files. Post-commit reruns: feature 3/3 pass;
gate fixtures 4/4 pass; real gate exit 1 with 1927 hits; OpenAPI lint exit 0.
