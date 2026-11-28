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

Remaining T14: frontend reversal action/form and field-profile masking; MCP
evidence remains T18.
