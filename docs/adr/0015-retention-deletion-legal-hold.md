# ADR-0015: Retention matrix, evidence-backed deletion and legal-hold precedence

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-18
- **Supersedes:** none
- **Review trigger:** legal/finance changes a retention period, a new data class or file type is added, legal-hold creation/release becomes an API/Workflow surface, audit logs are purged in production, or a purge is proposed without an approver and a restorable snapshot

## Context and trigger

D-18 sets minimum retention periods for payroll payment records, accounting
vouchers and ledgers, financial reports, employment contracts, personal data,
and audit/security logs. It also makes legal hold override expiry and requires
deletion or anonymization at the end of the applicable period. Getting this
wrong is irreversible in both directions: deleting too early destroys legally
required evidence, while retaining personal data too long violates minimum-
necessary and privacy obligations.

The repository implements a code-owned retention catalogue, a read-only preview,
a dry-run-by-default purge, legal-hold skipping, and purge-run/audit evidence.
It does not implement a legal-hold administration UI, a deletion-approval
Workflow, anonymization, a complete report-retention matrix, or a safe
module-owned deletion contract for every table. This ADR records the implemented
matrix and those explicit gaps.

## Decision

1. **Retention is a code-owned catalogue.** `RetentionClass::definitions()` is
   the authoritative matrix for this reference build. Each entry declares a
   label, owning table, timestamp column, period in months or `permanent`, and
   business owner. The current entries are:
   - `payroll_payment_records`: `payslips`, cutoff from `published_at`, 24 months;
   - `accounting_vouchers`: `payroll_runs`, cutoff from `closed_at`, 360 months;
   - `employment_contracts`: `employment_terms`, cutoff from `recorded_at`,
     24 months;
   - `security_audit_logs`: `audit_events`, cutoff from `occurred_at`, 36 months;
   - `annual_financial_reports`: `report_runs`, permanent (never purged).
   These are minimums, not maximums; a legal hold and a longer legal/financial
   requirement always win.
2. **Preview is a pure read.** `retention:preview` and
   `RetentionService::preview()` compute candidate counts, held counts,
   purgeable counts, cutoff, availability, and sample ids without deleting
   anything. A missing table or timestamp fails closed with `available: false`
   rather than reporting a false zero; permanent classes report no candidates.
3. **Purge is dry-run by default and requires explicit confirmation.**
   `retention:purge --class=<class>` performs a preview/dry run unless
   `--confirm` is supplied. A confirmed purge creates a `retention_purge_runs`
   row with mode/status/candidate/held/purged counts and a JSON report, deletes
   only rows strictly older than the cutoff, and writes an append-only
   `retention.purged` audit event with the class, run id, held-skipped count,
   purged count, and table name. The command exits non-zero on unavailable/
   permanent classes or exceptions.
4. **Legal hold has absolute precedence.** `LegalHold` rows have tenant,
   retention class, subject type, subject id or `*`, reason, case reference,
   start/end, release time, and creator. `RetentionService::heldIds()` considers
   only holds that are not released, have started, and are open-ended or not yet
   ended. A hold matching the table or `*` and either the specific row id or `*`
   is excluded from deletion. Held rows remain visible in preview counts and are
   never purged by the command.
5. **Deletion must leave evidence.** Every confirmed purge produces both a
   `retention_purge_runs` record and a `retention.purged` audit event; the
   `--json` output includes the preview, execution flag, purged count, and run
   id. This gives an operator a reviewable record of what class was evaluated,
   how many rows were candidates/held/purged, and when. The audit event itself
   is created through the Platform audit table and is intended to be
   append-only for ordinary operations.
6. **Append-only audit is preserved for normal use.** `AuditEvent` model hooks
   reject update and delete, and `AuditAppendOnlyTest` verifies it. The
   `security_audit_logs` retention class is the deliberate expiry exception: if
   an approved retention job removes audit rows after the 36-month minimum, that
   deletion is itself a privileged, confirmed purge with a purge-run record.
   Ordinary application code and HTTP surfaces have no audit-delete route.
7. **Retention is tenant-scoped and table-owner-aware in intent.**
   `RetentionClass` declares an owner for every class; the service takes a
   tenant id and filters by `tenant_id`. Platform owns the retention catalogue,
   purge-run records, legal holds, and audit evidence. Purging another module's
   table through a dynamic table name is a known architecture exception that
   must be replaced by module-owned retention contracts before production.
8. **PIPL/legal-hold and UAT-13 are acceptance gates.** UAT-13
   (`RetentionTest + legal hold`) and the joint audit's E-section requirement
   "retention and legal hold; deletion requires approval and evidence" are the
   acceptance basis. The code evidence is necessary but not sufficient: legal
   must confirm the applicable matrix, legal-hold procedure, deletion approvals,
   and whether deletion or anonymization is required for each class (GATE).

### Known limitations and honest gaps

- **Deletion approval is not a Workflow or two-person control.** The purge
  command requires only `--confirm`; it accepts no approver id, approval case,
  or second-person evidence. `RetentionPurgeCommand` calls
  `RetentionService::purge()` without an actor user id, so the resulting
  `retention.purged` audit event is recorded as `SYSTEM` even when a human ran
  the command. This does not yet satisfy "deletion requires approval and
  evidence" at the control level; the evidence is a purge-run row and audit
  event, not an approved Case/Decision.
- **Legal-hold administration is not exposed.** `LegalHold` is a model/table and
  is exercised by `RetentionTest`, but there is no authenticated HTTP/CLI
  create/release workflow, no approval, and no notification to legal. Holds can
  currently be inserted only by code/seeders/tests or direct database access.
- **The retention matrix is incomplete against D-18.** There is no
  `monthly/quarterly/semiannual financial reports 10 years` class; only annual
  reports are represented (permanent). `employment_contracts` uses
  `employment_terms.recorded_at` with a 24-month period, but the requirement is
  at least until termination plus two years; an active employee's old contract
  row could become a candidate while the employment is still active. The matrix
  must be corrected before production.
- **No anonymization path.** Purge deletes rows; D-18 permits deletion or
  anonymization at expiry. There is no field-level anonymization for persons,
  no redaction of historical payroll/payslip data, and no data-subject deletion
  workflow beyond the document/file surfaces.
- **No file-object retention class.** `file_objects` carries a retention class
  and legal hold, but `RetentionClass` has no file class and
  `LocalPrivateFileStore` hardcodes `REPORT_EXPORT_24H`. Private document bytes
  are not covered by the retention purge service.
- **Cross-module purge is a known architecture exception.** `RetentionService`
  uses dynamic `DB::table($definition['table'])` for classes owned by Payroll,
  People, Reporting, and Platform. `arch:test` currently emits 16 non-blocking
  warnings overall, including three `RetentionService` "cannot resolve dynamic
  table name to an owning module" warnings and other undocumented-owner/dynamic
  table warnings. This ADR records the limitation; it does not claim full table
  ownership resolution. Before production, deletion must go through each owning
  module's retention contract or a reviewed, allowlisted exception.
- **Audit-log purge conflicts with append-only by design unless controlled.**
  The 36-month `security_audit_logs` purge can delete audit rows through raw
  table deletion, bypassing the Eloquent append-only guard. That is acceptable
  only as an explicitly approved retention job with a snapshot/export and legal
  review; the current command's confirm-only control is not enough.
- **No production scheduling, archival, or restore drill.** There is no
  scheduler that runs retention previews/purges, no cold archive before delete,
  no completion alert, and no restore verification for purged data. These are
  operational GATEs.
- **No direct retention command tests.** `RetentionTest` exercises the service,
  legal-hold skip, dry run, permanent-class guard, and matrix presence, but not
  the `retention:preview`/`retention:purge` command output, exit codes, or
  `--confirm` path. Do not claim command-level test coverage.
- **Demo evidence is not a retention sign-off.** The only recorded operational
  instruction is in `evidence/logs/demo-verify.txt` ("Run retention:preview
  --all to confirm legal-hold visibility without deleting data"); there is no
  production retention/legal-hold evidence package.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Delete automatically as soon as a row is older than a configured number of months | Legal hold, litigation, audits, payroll/tax obligations, and active-contract exceptions must override expiry; automatic deletion without preview/approval risks irreversible loss. |
| Keep everything forever | Violates minimum-necessary/privacy obligations, increases breach blast radius, and makes storage/cost unbounded; D-18 requires deletion or anonymization at expiry. |
| Let each module delete its own rows with no central evidence | Produces inconsistent retention decisions and no tenant-wide purge-run/legal-hold view; a central catalogue plus module-owned execution is the intended direction. |
| Treat a legal-hold flag as a UI-only warning | A UI warning does not stop a batch delete; the held ids must be excluded by the deletion predicate in the same run. |
| Purge audit logs with the same unrestricted path as business data | Audit is append-only evidence; only a separately approved expiry exception with purge-run/audit evidence and legal review may remove it. |
| Use row counts only as deletion evidence | Counts do not prove which rows were skipped or purged; the service records purge-run reports and audit evidence, and a production control still needs approval and restorable backup. |
| Anonymize by nulling random columns without a field map | Can corrupt payroll/contract evidence and does not satisfy a legal obligation to retain the record; anonymization needs a per-field legal design, not an ad hoc update. |

## Consequences

Positive:

- retention periods and owners are code-owned and reviewable;
- preview is read-only and distinguishes candidates, held, and purgeable rows;
- purge is dry-run by default and requires explicit confirmation;
- legal holds are enforced in the deletion predicate, not just displayed;
- every confirmed purge produces a purge-run record and audit event;
- permanent annual reports are never purged by the command;
- tenant scoping and missing-table fail-closed behavior are explicit.

Negative / costs:

- the current deletion control is a command/`--confirm`, not an approved
  two-person Workflow; human/system attribution is incomplete;
- legal holds cannot be administered through the product;
- the matrix needs legal correction and completion before production;
- raw cross-module table deletion and audit-log deletion require exception
  review and architecture cleanup;
- no anonymization, file-retention coverage, archive/restore, or scheduler is
  delivered;
- `arch:test` ownership warnings remain (16 total, three RetentionService
  dynamic-table warnings).

Data/API/permission/migration/operations/training impact: Platform owns
`legal_holds`, `retention_purge_runs`, and `audit_events`; the retention
catalogue names tables owned by Payroll, People, Reporting, and Platform.
Retention is destructive and must be limited to a privileged operator with a
reviewed runbook. Legal/finance own the matrix and hold decisions; HR owns
employment-contract lifecycle. Production requires a scheduler, approval
workflow, archive/restore plan, incident procedure, and operator training that
preview is not deletion and `--confirm` is not legal approval.

## Validation

- `php artisan test --filter=RetentionTest` — preview counts without deletion,
  legal-hold skip with purge-run and audit evidence, dry-run no-op, matrix
  presence/periods/permanent flag, and permanent classes never purged.
- `php artisan retention:preview --class=payroll_payment_records
  --tenant=1 --as-of=YYYY-MM-DD` — read-only candidate/held/purgeable report;
  `--all` previews every class and `--json` is machine-readable.
- `php artisan retention:purge --class=payroll_payment_records --tenant=1
  --as-of=YYYY-MM-DD` — dry run with no deletion; add `--confirm` to execute
  after approval. The command writes a purge-run record and a
  `retention.purged` audit event; it exits non-zero for unavailable/permanent
  classes or exceptions.
- `php artisan test --filter=AuditAppendOnlyTest` — ordinary audit update/delete
  is rejected; purge-time audit deletion must be an explicit, separately
  reviewed retention exception.
- `php artisan test --filter=DemoSeedTest` — synthetic SHADOW demo data only,
  reset refused in production, and the demo verification output includes the
  retention preview instruction; this is not a retention acceptance test.
- `php artisan arch:test` — module/table ownership gate. The current recorded run
  is `Module boundaries OK` with **16 non-blocking warnings**, including three
  `RetentionService.php` dynamic-table warnings and warnings for
  `feature_flags`, migration dynamic tables, and other undocumented owners.
  This is a known limitation and review trigger, not a clean ownership result.
- `docs/10-joint-audit-acceptance.md` — E-section retention/legal-hold
  requirement and UAT-13 (`RetentionTest + legal hold`) are acceptance evidence
  pointers. Legal sign-off and a production deletion drill remain GATEs.

## Security and privacy

Retention purge is destructive and must be restricted to a privileged,
audited operator and run against the correct tenant. Preview must not expose
more row data than the retained sample ids. Legal holds are tenant-scoped and
must override expiry. Purge-run reports and audit events must not contain PII
values; the current report stores ids/counts/table names and the audit event
stores class/run/counts. The `security_audit_logs` class must not become a
backdoor for routine audit tampering; any audit deletion requires a separately
approved retention exception, legal/security review, and a restorable snapshot.
Production retention requires a privacy/legal data map, deletion approval,
anonymization policy, KMS-backed archive, access controls, and restore drills
(GATE). No real personal data may be used in retention tests or seeds.

## Review triggers and rollback

Re-review before any production purge, before changing a retention period, when
adding a new table/file class, when legal hold creation/release becomes an API,
or when audit-log deletion is enabled. Also re-review when the
`employment_contracts` cutoff is corrected to termination-relative retention,
when report retention classes are completed, and when `RetentionService` is
replaced by module-owned deletion contracts.

Rollback path: retention deletion is irreversible, so the only safe rollback is
a pre-purge snapshot/archive and a restore under DBA/legal supervision. Pause
the scheduler and `--confirm` execution, release the affected holds only through
legal, and preserve the purge-run/audit evidence. A matrix change requires a new
ADR/decision revision; never shorten a period or delete audit evidence by
editing code silently. Owner: legal/compliance with HR, finance, platform
security, and DBA/operations.
