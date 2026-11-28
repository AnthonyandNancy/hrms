# T14 Benefits Payroll Input and Reversals

## T14a — multi-plan transactions and append-only reversal

- Added `entry_type`, `reversal_of_id`, `reversed_by_id`, `reason` and
  `source_hash` to `benefit_transactions`.
- Removed the old one-transaction-per-enrollment-period and
  one-transaction-per-payroll-run constraints; added a unique
  `(enrollment_id, period, entry_type)` and unique `reversal_of_id`.
- A payroll run may now carry multiple plans and multiple periods; a duplicate
  RECORD for one enrollment/period still returns `BENEFIT_PERIOD_DUPLICATE`.
- Added `BenefitService::reverseTransaction()`: appends a REVERSAL row,
  preserves the original row, requires a reason, rejects duplicate reversal,
  blocks frozen payroll links and audits the command.
- Added `POST /v1/benefits/transactions/{id}/reverse`, OpenAPI, generated
  action manifest and route ledger entry.

## T14b — Payroll input port and employer cost

- Added `BenefitPayrollInputService`, replacing the empty adapter.
- It nets RECORD minus REVERSAL per enrollment, returns all plans for one
  employee/period, and freezes source transaction ids into Snapshot v2.
- Added `EmployerCostLine` and `otherEmployerCosts` through
  `PayrollCalculationInput` / `PayrollCalculator`:
  - employee benefit amounts enter deductions;
  - employer benefit amounts enter employer cost and payslip employer lines;
  - gross/net remain unchanged by employer-only cost.
- Payroll snapshots already frozen at lock are unaffected by a later benefit
  reversal; the test explicitly reverses after lock and verifies the frozen
  snapshot still contains both plans.
- Verification: 202 payroll/platform/unit tests / 30,886 assertions PASS;
  Pint, PHPStan, arch and frontend typecheck/lint green.

## T14c — frontend reversal action

- Benefits transaction ledger now shows `已冲销` for reversal rows and their
  original RECORD, with a permission-gated `冲销` action for unreversed rows.
- The action asks for a reason of at least 10 characters, sends it through
  `POST /v1/benefits/transactions/{id}/reverse` with a fresh idempotency key,
  reloads the ledger and maps duplicate/frozen failures to Chinese copy.
- BenefitsWorkspace regression now covers the prompt, command endpoint,
  reason payload and success evidence.

Remaining T14: field-profile masking for non-P3 readers; MCP evidence remains
T18.

## T14d — benefit amount field-profile masking

- Added P3 field schemas for `benefit_enrollment` and `benefit_transaction`:
  base amount, employee/employer contributions, employee/employer amounts and
  total; rates/status/dates remain at lower profiles.
- Benefit list reads now resolve `benefit.read` field projection per row and
  omit P3 amount fields unless the binding carries P3. Production authorizer
  records field-name-only sensitive-read evidence.
- Write responses still echo caller-supplied amounts, matching the existing
  write-echo contract; list/detail projections never use that path.
- Frontend normalizers accept absent amount fields and render `金额受限` rather
  than `0` or throwing.
- Added backend P2/P3 masking regression and frontend masked-amount regression.
- Verification: benefit backend 10 tests / 94 assertions; BenefitsWorkspace 15
  tests; typecheck/lint/Pint/PHPStan green.
