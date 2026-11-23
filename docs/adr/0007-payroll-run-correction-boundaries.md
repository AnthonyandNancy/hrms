# ADR-0007: Payroll run states, immutable finalized runs, correction deltas and payment evidence

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-06, D-14, D-16
- **Supersedes:** none
- **Review trigger:** a finalized run must be edited in place, a correction cannot be represented as a new delta run, payment/receipt evidence is not available before `PAID`, or payroll needs an external bank/tax integration rather than the CSV adapter boundary

## Context and trigger

Payroll is the first workflow in the repository where a late input can change money
that has already been paid, reported, or closed. The approved plan therefore treats
the run lifecycle and correction boundary as irreversible: a published or closed
run is historical evidence, and later changes must create a new payable fact rather
than rewrite the old one (D-06, D-14, D-16).

The code already has a state machine, append-only input snapshots, sealed-row
guards, a Workflow-backed approval, correction runs that store deltas, and a
read-only reconciliation command. The decision is to keep those boundaries as the
payroll contract rather than allowing per-endpoint status updates or in-place
repairs. This ADR records the implemented boundary and the honest limits of the
reference build: the repository does not file taxes, clear bank payments, render
payslip PDFs, or run a real parallel payroll without a legacy export.

## Decision

1. **One lifecycle writer.** `PayrollRunStatus` defines `DRAFT`,
   `INPUT_LOCKED`, `CALCULATED`, `REVIEWED`, `APPROVED`, `PUBLISHED`, `PAID`,
   `CLOSED`, and `CANCELLED`. `PayrollStateMachine` is the single writer of
   `payroll_runs.status`; every legal transition is listed in its `TRANSITIONS`
   table and an illegal transition is a 409 with `INVALID_RUN_TRANSITION`.
2. **Freeze inputs before calculation.** `PayrollRunService::lockInputs()` moves
   a draft to `INPUT_LOCKED` and asks `SnapshotService` to write an immutable,
   normalized per-employment snapshot plus a canonical `input_hash`. Snapshots
   are append-only at the model and database levels. Calculation is accepted
   only from `INPUT_LOCKED`; changing Time or People facts afterwards cannot
   mutate the existing snapshot.
3. **Separate approval from the fact.** Review, approval, publication, and
   payment marking require separate actors. `ApprovalService::approve()` opens
   and decides a Workflow `payroll.approval` case through
   `PayrollApprovalWorkflow`; the approval effect advances the run, and
   `approval_case_id`/`approval_effect_status` retain the linkage. Approval is
   not a bare status write.
4. **Seal publication.** `publish()` folds YTD exactly once, stamps payslips,
   and transitions to `PUBLISHED`. `PayrollRun` model hooks and SQLite triggers
   reject post-publication changes to financial columns, input hash, period,
   scheme, cutoff, correction linkage, and sealed payslip lines. Sealed runs
   cannot be deleted. `PAID` and `CLOSED` are progressively stronger seals, not
   mutable labels.
5. **Payment is evidence-backed.** `markPaid()` accepts either a
   reconciliation/receipt reference or a manual confirmation reason of at least
   10 characters, records an audit event, and only then transitions
   `PUBLISHED -> PAID`. A separate `ReceiptReconciliationService` can attach a
   BANK/TAX acknowledgement hash to a paid/closed run and compare it with the
   stored export hash; it is not wired to an HTTP route in this build. The
   repository does not clear bank funds or file tax returns.
6. **Corrections are new runs.** `CorrectionService::create()` accepts only a
   `PUBLISHED`, `PAID`, or `CLOSED` original, requires a reason of at least 10
   characters, creates a `CORRECTION` run with `correction_of_run_id`, reuses the
   original employment population, and re-snapshots inputs. The original run,
   its snapshot, payslip, and receipts are never rewritten.
7. **Deltas are explicit and balanced.** `CalculationService::storeCorrection()`
   uses `PayrollCorrectionDelta` (revised calculation minus the original
   baseline) and writes delta-only payslip lines. `ReconciliationService::chain()`
   checks that the original plus every published correction equals the chain's
   payslip totals. Prior corrections remain separate payable rows and are not
   folded into a later baseline.
8. **Reopen is pre-publication only.** `ReopenService` accepts only
   `CALCULATED`, `REVIEWED`, or `APPROVED`, requires two distinct approver user
   ids, rejects the requester as an approver, requires a reason of at least 10
   characters, increments `snapshot_version`, re-snapshots, and refuses when the
   input hash did not change. It returns the run to `INPUT_LOCKED`. Published,
   paid, and closed runs are corrected, never reopened.
9. **Keep four time axes.** `PayrollCalculationInput` and snapshots carry earning
   period, posting period, pay date, and cutoff timestamp as separate fields. A
   January correction paid in February remains a January earning-period
   correction with an explicit posting period and pay date.
10. **External boundaries are adapters, not hidden production claims.**
    `ExportService` produces CSV payment and tax-preparation adapters with a
    header saying they are not bank files or filings, stores the exact SHA-256,
    and audits the generation. `PayrollParallelCheckCommand` compares a
    documented `employment_id,gross_cents,net_cents` CSV and exits 2 with
    `NOT_EXECUTED` semantics when no legacy export is supplied. No bank/tax
    filing, payslip PDF, or external clearing is claimed.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Edit the published/closed run and recalculate in place | Destroys the evidence used by employees, finance, audit, and tax reconciliation; makes a known and an unknown past state indistinguishable. |
| Correct by overwriting the original payslip and keeping only a new total | Loses the delta needed to explain what changed, cannot reconcile prior YTD, and makes a later correction double-count or erase earlier adjustments. |
| Treat `PUBLISHED` as reversible until a separate accounting close | Publication is the employee-visible and financial fact boundary; allowing edits between publication and accounting close creates untraceable payroll revisions. |
| Use a generic status endpoint or direct model updates | Bypasses actor columns, timestamps, audit records, SOD checks, Workflow approval, and the sealed-column guards. |
| Make reopen the universal correction path | Reopen is a controlled pre-publication recovery; published/paid runs must remain historical and be corrected by a new run. |
| Send bank/tax data directly from the run service | Expands the security and regulatory boundary, makes external failures part of the local transaction, and cannot be tested without a real processor. |

## Consequences

Positive:

- every paid amount has a reproducible snapshot, rule version, approval case,
  and audit trail;
- late changes are additive and remain reconcilable across a correction chain;
- sealed runs cannot silently change through HTTP, Eloquent, or SQLite writes in
  the tested database;
- payroll reconciliation and parallel-run reporting have explicit exit codes and
  machine-readable evidence;
- the same run model supports monthly, off-cycle, bonus, and correction runs
  without a second payroll store.

Negative / costs:

- corrections are operationally slower than an edit, and finance must understand
  delta reporting;
- a correction can require a new approval cycle, new payable row, and new
  reconciliation evidence;
- reopen approvers are currently supplied user ids in `reopen_approvals_json`,
  not independent Workflow cases; there is no separate reopen case template;
- the CSV and receipt services are adapters only; bank/tax filing, payslip PDF,
  an HTTP receipt-reconciliation surface, and an automatic legacy-system extract
  remain outside this slice;
- YTD, payment, and correction correctness still depend on production statutory
  parameters and finance sign-off (GATE).

Data/API/permission/migration/operations/training impact: payroll tables are
owned by Payroll; mutations are capability-guarded and run-anchored; snapshots
and sealed facts are append-only; migrations remain forward-only. Operators need
training on correction-vs-reopen and on the difference between `PUBLISHED`,
`PAID`, and `CLOSED`. The DEMO parameter seeder is synthetic and must not be
used for real payroll.

## Validation

- `php artisan test --filter=PayrollRunLifecycleTest` — full lifecycle, illegal
  transitions, payslip immutability, database-level sealed updates, append-only
  snapshots, and preparer/reviewer SOD denial.
- `php artisan test --filter=CorrectionRunTest` — delta-only correction,
  original-plus-correction reconciliation, multiple corrections, YTD adjustment,
  and rejection of a draft original.
- `php artisan test --filter=PayrollReopenTest` — changed-hash reopen, refusal
  when nothing changed, effective-dated parameter versions retained by closed
  runs, and required parameter evidence.
- `php artisan test --filter=MarkPaidEvidenceTest` — mark-paid requires a
  reference or a manual confirmation reason and audits the transition.
- `php artisan test --filter=PayrollCommandTest` — parallel-check 0/1/2 exits,
  reconciliation pass/fail, and the DEMO parameter command's evidence actor.
- `php artisan payroll:reconciliation --run=<run-id>` — run/employee/payslip/
  correction-chain totals; exits non-zero on mismatch.
- `php artisan payroll:parallel-check --period=YYYY-MM --old-file=<csv>` —
  the command is implemented and tested with synthetic files. The repository's
  recorded evidence is `exit=2 (NOT_EXECUTED: no legacy export supplied)`, not a
  clean production parallel run.
- `php artisan arch:test` — Payroll's module boundary and table ownership checks.
  The current run is non-blocking with 16 warnings across the repository (three
  are RetentionService dynamic-table warnings); this ADR does not claim zero
  warnings.

## Security and privacy

SOD is enforced in `ApprovalService` and the capability catalog; the preparer,
reviewer, approver, and publisher are distinct. Payment evidence records
`reference_present` and `manual_confirmation_present` rather than copying bank
credentials or PII. Audit payloads carry IDs, statuses, hashes, and reason
presence; they do not carry national identity, bank account, or contact
plaintext. The CSV adapters are explicitly labelled and are not filing or
clearing instructions; finance/legal approval of real statutory parameters and
the real bank/tax integration remain organizational GATEs.

## Review triggers and rollback

Re-review before allowing any in-place mutation of a published/paid run, before
making correction runs inherit a fresh employment population, before replacing
user-id reopen approvals with a Workflow case, or before adding a real bank/tax
adapter. Also re-review if correction chains no longer reconcile within one
cent, if YTD can be folded twice, or if new payroll statuses are proposed without
a full transition/effect/audit analysis.

Rollback path: keep the current additive model and disable a new adapter or
feature at the application boundary; do not rewrite sealed rows. Removing a
status or redefining a transition requires a new ADR and a migration plan for
existing runs. Owner: payroll technical owner with finance and audit review.
