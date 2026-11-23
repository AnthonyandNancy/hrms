# ADR-0010: Migration order, SHADOW rehearsal, reconciliation and source-of-truth cutover

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-21, D-23
- **Supersedes:** none
- **Review trigger:** a real legacy extract/source owner is available, the shadow target is replaced by a live database target, cutover waves are resequenced, or source-of-truth routing/rollback evidence must cover a real production cutover

## Context and trigger

The approved plan requires a staged, per-domain cutover with a source-of-truth
map, owner sign-off, three rehearsals, itemized reconciliation, and a rollback
path that does not overwrite already paid/taxed/signed facts (D-21, D-23). The
repository is a reference implementation: it has a real migration framework and
rehearsal over synthetic fixtures, but it has not performed a production
migration or a live source-of-truth switch. That distinction must be explicit.

The implemented path is a two-phase import: stage and validate the whole CSV,
then apply normalized rows through a domain-owned target port in one
transaction. The current rehearsal target is `SHADOW`, which writes explicitly
prefixed rehearsal rows rather than business tables. The database target exists
but reports itself unavailable until the owning module schemas/columns are
present. This ADR records the import order, reconciliation and rollback
semantics, and the organizational gates that remain.

## Decision

1. **Fixed domain order.** `MigrationDomain` is the only supported order:
   `organizations -> people -> assignments -> terms -> leave_openings ->
   payroll_ytd`. Dependencies are explicit (`dependsOn()`), and the runner
   refuses a database/shadow apply when an earlier domain does not have an
   `APPLIED` run for that tenant. The cutover waves are organizations/people/
   assignments/terms (wave 1), leave openings (wave 2), and payroll YTD (wave 3).
2. **Validate before write.** `MigrationRunner` records a `migration_runs` row
   before touching a target, parses the CSV, stages every source row with a
   source-row hash and normalized JSON, records `migration_issues`, and only
   applies when there are zero ERROR issues. A validation run is read-only and
   exits successfully only for a fully valid fixture. Apply failures are
   recorded as `APPLY_FAILED`/`FAILED` with a sanitized reason and a rollback
   report; missing targets fail closed rather than silently reporting zero.
3. **Idempotent natural keys.** Each target port stamps migrated rows with
   `source_type`/`source_id` and uses a domain natural key. Repeated application
   skips unchanged rows instead of duplicating them. The shadow target has a
   unique `(tenant_id, domain, natural_key)` key and stores a payload hash;
   repeated identical rows return `SKIPPED`.
4. **SHADOW is visibly not live.** `ShadowMigrationTarget` writes
   `domain = "SHADOW:<domain>"` into `migration_shadow_rows` and includes
   `target: SHADOW` in every run report. `migrate:rehearsal` forces the shadow
   target, and its report states "All rows are SHADOW rehearsal records; none
   are live business data." A shadow result is never evidence of a live import.
5. **Reconciliation is count and sum based.** `MigrationReconciler` compares
   staged source row count and domain-declared integer sums with target metrics.
   Leave openings reconcile `quantity_minutes`; payroll YTD reconciles
   `taxable_cents`, `tax_withheld_cents`, `si_cents`, `hf_cents`,
   `gross_cents`, and `net_cents`. Any mismatch becomes a persisted ERROR issue
   and a non-zero command result; no tolerance or "close enough" pass exists.
6. **Rehearsal stops at a failed wave.** `migrate:rehearsal` validates and
   applies every domain in order, reconciles each applied run, writes a JSON
   report with per-domain status/reconciliation/pass, and stops when a domain
   fails so later domains are not validated against missing references.
   `deploy/scripts/migration_rehearsal.sh` creates an isolated SQLite database,
   runs migrations, invokes the rehearsal, checks the report JSON, and prints a
   report SHA-256.
7. **Rollback is a reviewed plan, not an automatic inverse.** `MigrationRollbackPlanner`
   produces a JSON plan with target rows, unresolved errors, preconditions
   (snapshot/PITR, stop writes, two-person approval, reconcile inverse), a plan
   digest, and an explicit `executed: false`. The command refuses `--execute`
   without `--confirm-destructive` and still does not execute statements; a DBA
   follows the runbook. It explicitly notes the irreversible risks for leave
   openings and payroll YTD.
8. **Source-of-truth is a cutover decision per domain.** The implementation
   models waves and reports but does not flip traffic between systems. The
   approved cutover requires an explicit source-of-truth map per data domain,
   a data owner sign-off, and independent go/no-go for each wave. Legacy remains
   read-only for 60–90 days in the approved plan; already paid/taxed/signed/
   notified facts are never rolled back by migration.

### What the implementation actually demonstrates

- The repository's evidence is synthetic. `evidence/logs/migration-rehearsal.txt`
  shows six domains applied to `SHADOW` and reconciling:
  organizations 8, people 12, assignments 12, terms 12, leave openings 12,
  payroll YTD 12 rows, all `PASS`. `evidence/logs/migration-rehearsal-script.txt`
  shows the isolated SQLite script writing a report SHA-256 and all six domains
  at `PASS`.
- `evidence/logs/demo-seed.txt` similarly shows six domains in `SHADOW` mode
  with the warning that business tables owned by parallel modules are not merged
  and the domains are rehearsal-only.
- The evidence does **not** prove a real legacy export, a production freeze
  window, a live database target apply, source-of-truth routing, legacy
  read-only operation, a three-rehearsal cadence, or a completed go/no-go.
  Those are GATEs, not PASSes.

### Known limitations and unresolved ownership

- The current `DatabaseMigrationTarget` is a generic, guarded adapter; it
  reports unavailable until the owning tables/columns exist. A real target must
  be validated against the actual production schema and writing services, not
  assumed from the reference schema.
- Cross-module table writes in the generic adapter are deliberately scoped to
  bootstrap migration; `arch:test` currently warns about dynamic table names
  and tables without a documented owner (16 non-blocking warnings in the
  recorded run). This is an accepted reference-implementation limitation, not
  evidence that every table has clean ownership.
- There is no persistent, first-class ID crosswalk table. The mapping CSV and
  staging rows retain source keys and target ids, and migrated rows carry
  `source_type`/`source_id`, but D-23's "ID mapping table retained long-term"
  requirement is not fully implemented as a dedicated durable crosswalk.
- There is no data-owner sign-off UI or automated `no owner => no migration`
  enforcement beyond the external mapping file/runbook; owner approval is
  organizational.
- There is no live cutover controller, traffic router, legacy read-only switch,
  60–90 day legacy window implementation, go/no-go workflow, or 60-minute
  rollback drill evidence in the codebase. The rollback planner is plan-only.
- Parallel payroll cutover depends on a real legacy export. The recorded
  `payroll:parallel-check` evidence is exit 2 (`NOT_EXECUTED`) because no legacy
  export was supplied; that is not a clean parallel result.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Big-bang migration in one transaction without domain waves | A failure anywhere loses the whole attempt, makes reconciliation ambiguous, and prevents independent go/no-go; payroll and leave opening balances need domain-specific checks. |
| Write directly to business tables from the importer | Violates one-writer-per-table and bypasses domain services; a generic writer cannot reproduce a module's invariants or audit trail. |
| Treat a validation run as a successful rehearsal | Validation proves parsing and rules, not target application, count/sum reconciliation, or rollback; it would enable a false cutover claim. |
| Reconcile only row counts | Counts can match while money or leave minutes are wrong; integer sums for YTD and leave openings are mandatory. |
| Automatically execute a generated inverse SQL plan | Destructive rollback can overwrite consumed leave, closed payroll, or downstream references; it needs snapshot/PITR, stop-writes, two-person approval, and a DBA runbook. |
| Treat SHADOW rows as live because the shape matches | A rehearsal that cannot be distinguished from production data is a data-integrity and audit hazard; SHADOW must be visibly labelled in the table and report. |
| Switch all domains at once and keep legacy writable | Creates two sources of truth and silent divergence; the approved plan requires per-domain routing and a read-only legacy window. |

## Consequences

Positive:

- the apply order and dependencies are executable, not just documented;
- bad CSVs produce issues and no target writes instead of guessed rows;
- count and money sums are reconciled before a rehearsal can pass;
- SHADOW output is unambiguous and cannot be mistaken for live data;
- rollback has a reviewable plan and digest, even though execution is manual;
- repeated runs are idempotent for the tested targets.

Negative / costs:

- real source profiling, owner sign-off, and a legacy export are still required
  before a production cutover;
- the reference target adapter and ownership warnings need cleanup before a real
  apply;
- a durable ID crosswalk and owner-driven migration gate are not delivered;
- three rehearsals, a production-like freeze window, live source-of-truth
  routing, and a ≤60-minute rollback drill remain organizational GATEs;
- the current evidence is synthetic and cannot be used as a production
  acceptance record.

Data/API/permission/migration/operations/training impact: migration and staging
tables are Platform-owned; target writes go through module-owned ports. The
migration reports and rollback plan are operational evidence and may contain
source identifiers, so they must remain tenant-scoped and protected. Cutover
requires QA, data owners, finance/HR, DevOps/DBA, and business go/no-go owners.
Training must cover validation vs apply, SHADOW vs live, reconciliation
exceptions, and the fact that a rollback plan is not an executed rollback.

## Validation

- `php artisan test --filter=MigrationRehearsalTest` — all six domains validate,
  apply to SHADOW, reconcile, write a report, reject a non-positive round, and
  fail cleanly on a missing dataset; no non-SHADOW shadow rows are created.
- `php artisan test --filter=MigrationReconciliationTest` — leave-opening count/
  sum, all payroll-YTD totals, and injected count/sum mismatches fail
  reconciliation.
- `php artisan test --filter=MigrationCommandsTest` — validate command is
  read-only, broken fixture is non-zero, apply without a database target fails
  closed, SHADOW apply reconciles, rollback plan writes JSON and never executes,
  and unknown domains are rejected.
- `php artisan test --filter=MigrationIdempotencyTest` — repeated apply does not
  duplicate target rows; dry-run writes no target rows.
- `php artisan test --filter=MigrationValidateTest` — known-good fixtures validate
  for every domain; broken fixtures report expected issue codes and write no
  target rows; missing required headers fail without writes.
- `bash deploy/scripts/migration_rehearsal.sh --round=1 --dataset=demo` — isolated
  SQLite migrate + six-domain SHADOW rehearsal + report validation. The recorded
  run in `evidence/logs/migration-rehearsal-script.txt` reports six `PASS`
  domains and a report SHA-256; it is synthetic evidence, not a live cutover.
- `php artisan migrate:rehearsal --round=1 --dataset=demo --output=<path>` —
  direct command used by the test and script; exits non-zero if any domain
  validation/apply/reconciliation fails.
- `php artisan migrate:reconcile --run=<run-id>` — count/sum reconciliation for
  one run; exits non-zero when no applied runs or any mismatch.
- `php artisan migrate:rollback-plan --run=<run-id>` — writes a plan-only JSON
  with digest and irreversible notes; does not execute destructive SQL.
- `php artisan arch:test` — module boundaries; the current run has 16
  non-blocking warnings including dynamic migration/retention table names and
  undocumented owners, so this ADR does not claim a clean warning set.

## Security and privacy

Migration fixtures and tests use synthetic identifiers only. The runner records
source-file hashes, mapping hashes, row hashes, sanitized failures, and target
ids; it does not intentionally persist raw PII in reports. Shadow and staging
data remain protected data stores and must not be used as a production source
without the same access controls. Real source files, ID crosswalks, and rollback
evidence may contain sensitive employee data and must be handled under the data
owner's access, retention, and legal-hold rules. Production cutover needs a data
protection impact review and source-owner approvals (GATE).

## Review triggers and rollback

Re-review before any live database target is enabled, before the shadow target
is removed, before a cutover wave changes order, before relying on a rollback plan
in an incident, and before claiming a production migration is complete. Also
re-review if source-of-truth routing is automated, if a durable crosswalk is
added, or if reconciliation sums/owners change.

Rollback path: keep legacy routing available, stop writes, take a database
snapshot/PITR marker, reconcile the inverse plan, and follow the reviewed runbook
with two-person approval. The rollback planner itself is plan-only. Do not
restore or delete already paid/taxed/signed rows; correct those through payroll
correction, manual financial reconciliation, or legal process. Owner: data
migration lead with data owners, DBA, QA, finance/HR, and the project sponsor.
