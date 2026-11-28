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
