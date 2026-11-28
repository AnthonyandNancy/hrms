# T12 Mid-month Compensation Segments

## T12a — effective-dated compensation segments and weighted base

- Extended `CompensationVersionDirectory` with `segmentsForPeriod()`.
  ACTIVE compensation versions overlapping the earnings period are returned
  with clamped segment boundaries, salary, scheme/version and overrides.
- Snapshot v2 now freezes `compensation_segments` for every employee.
- Calculation computes a weekday-weighted base salary from the frozen segments
  before invoking the payroll calculator. This keeps tax/social-insurance
  calculation monthly once, while base income respects mid-month changes.
- Golden regression: April 2026 with 11 working days at 8,000 and 11 working
  days at 9,000 produces gross `850000` cents.
- Verification: payroll suite 110 tests / 525 assertions; Pint, PHPStan and
  arch green.

Remaining T12: explicit once-only tax/SI assertion, payslip line segment
source expansion and UI segment drilldown.

## T12b — once-only charges, provenance and segment drilldown

- Added a same-gross control month: a single 8,500 segment produces exactly the
  same employee SI, housing fund, tax and deductions as the two-segment
  8,000→9,000 month. Monthly charges are therefore applied once.
- BASE_SALARY source is `employee_compensation_segments` with both frozen
  compensation ids retained for traceability.
- Snapshot now blocks overlapping segments and gaps not justified by employment
  terms boundaries (`COMPENSATION_SEGMENT_OVERLAP`,
  `COMPENSATION_SEGMENT_GAP`), preventing a partial month from silently using a
  lower rate.
- Payroll run detail input tab expands frozen compensation segments with date
  bounds, source ids and exact money; the UI does not recalculate locally.
- Added frontend regression and overlap→blocked→review-denied backend regression.
- Verification: payroll suite 111 tests / 537 assertions; payroll UI 15 tests;
  typecheck, lint, Pint, PHPStan and arch green.
