# T13 Corrections and Signed YTD

## T13a — append-only signed ledger

- Added `payroll_ytd_ledger_entries` with signed taxable/tax/SI/HF/gross/net
  amounts, source run/employee, source type, source hash, carry-forward and a
  unique `(source_run_id, employment_id, entry_type)` publication key.
- SQLite triggers reject updates and deletes, making the ledger append-only.
- `PayrollYtdService` now:
  - writes exactly one ledger entry per published run/employee;
  - applies signed deltas without clamping negative net to zero;
  - stores `carry_forward_cents = min(0, cumulative net)` explicitly;
  - returns the existing projection on duplicate publication without folding
    the ledger twice.
- Added regressions for negative YTD, signed correction entries, idempotent
  republish, cumulative negative/positive correction order and reconciliation
  ledger sums.
- Verification: payroll suite 113 tests / 568 assertions; Pint, PHPStan and
  arch green.

## T13b — correction chain identity

- `payroll_runs` now records `correction_baseline_run_id`,
  `correction_sequence`, `prior_correction_ids_json` and a unique
  `(correction_of_run_id, period)` key.
- Correction creation points the second correction at the first correction
  baseline and stores prior correction ids.
- Run detail exposes the chain identity; reconciliation chain exposes the
  signed YTD ledger.
- Payroll run detail input/reconciliation view renders signed YTD entries and
  negative carry-forward with exact integer-cent money.
- Verification: payroll 113 tests / 568 assertions; payroll UI 15 tests;
  typecheck/lint green.

Remaining T13/AC-12: exact 8080→8300→8350 target-delta scenario and explicit
cumulative target/delta fields on the run; MCP evidence remains T18.

## T13c — single-company ledger schema correction

- Gate `SingleCompanyMigrationTest`/`NoTenantNoLegalEntityTest` caught a
  `tenant_id` column in the new ledger. A forward-only migration
  `2026_11_28_001420_remove_tenant_from_ytd_ledger.php` drops the index and
  column; model writes no tenant identifier.
- Targeted single-company, no-tenant and correction tests: 16 tests / 422
  assertions PASS.

## T13c — cumulative target, approved baseline and current delta

- Correction snapshots now include the original plus all prior effective
  correction adjustments, not only the current settlement period.
- Correction calculation baseline is `original + all prior published
  corrections`; the current delta is `target - approved baseline`.
- Runs persist `correction_target_net_cents`,
  `correction_baseline_net_cents`, `correction_delta_net_cents` and
  `correction_cumulative_net_cents`.
- Fixed prior-correction line merging that double-counted the first
  correction's payslip line.
- AC-12 golden now uses a 9,617.96 salary fixture whose tax/SI/HF rounding
  yields original net `808000` cents; gross adjustments `26681` and `6065`
  produce target nets `830000` and `835000`, deltas `22000` and `5000`, and
  cumulative `835000` — exactly 8080.00 → 8300.00 → 8350.00.
- Payroll run detail renders correction target, approved baseline, current
  delta, cumulative amount and original/baseline run links.
- Verification: payroll 114 tests / 580 assertions; payroll UI 15 tests;
  typecheck/lint, Pint, PHPStan and arch green.

## T13d — unpaid correction void/retry

- Added independent correction state on payroll runs:
  - `correction_status` OPEN/VOID;
  - `correction_voided_at`, `correction_void_reason`.
- Removed the correction `(original, period)` unique hard constraint; one OPEN
  correction per period is enforced in the service, while prior VOID rows remain
  as audit evidence.
- `CorrectionService::voidUnpaid` only accepts an unpublished/unpaid correction,
  changes correction state only, and leaves run status/financial facts intact.
- Re-running create for the same period after VOID now returns a new correction
  with sequence/baseline computed from non-void corrections.
- Added `POST /v1/payroll/runs/{id}/corrections/void`:
  - `payroll.run.correct` + PAYROLL_RUN anchor + mfa.step-up + idempotency;
  - OpenAPI/action manifest/page required APIs/permission catalog/ledger sync.
- Payroll detail displays correction OPEN/VOID state, void evidence and a
  permission-gated void form; frontend API uses the real command.
- Regressions: service void→retry, published void rejection, real HTTP void.
- Verification: correction/payroll workspace 15 tests / 155 assertions; frontend
  detail/workspace 24 tests; typecheck/lint/Pint/PHPStan/arch green.
