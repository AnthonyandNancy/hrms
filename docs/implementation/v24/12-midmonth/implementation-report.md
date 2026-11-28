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
