# T15 Performance Bonus Payroll Link

## T15a — published performance snapshot to approved payroll bonus

- Added `score_scaled`, `grade` and `source_revision` to
  `performance_result_snapshots`; publication now records grade/final rating and
  a stable source revision hash.
- Added `performance_bonus_mappings` with snapshot/cycle/employment/item,
  period, amount, mapping version, status, approver and source revision.
- DRAFT mappings never enter Payroll; approval re-checks that the performance
  snapshot is published and writes audit evidence.
- Added `PerformanceBonusDirectoryService` and bound it to the Payroll-owned
  `PerformanceBonusDirectory` port. It returns only APPROVED mappings for the
  exact employee and payroll period whose snapshot is published.
- Added `PerformanceBonusPayrollTest`:
  - approved 2026-01 mapping freezes and enters gross;
  - DRAFT mapping is excluded;
  - approved future-period mapping is excluded;
  - Snapshot v2 contains one performance bonus with mapping provenance.
- Verification: 31 focused Talent/Payroll tests / 243 assertions; Pint,
  PHPStan and arch green.

Remaining T15: mapping management API/UI, Offer salary draft and training
attempt source chain; MCP evidence remains T18.

## T15b — Offer salary to Payroll compensation draft

- Added `offers.salary_scheme_code` and exposed it in offer create/list
  projections and OpenAPI.
- Offer acceptance now passes `base_salary_minor`, `salary_currency` and
  `salary_scheme_code` through the Lifecycle onboarding draft.
- `TalentOnboardingDraftService` preserves Offer salary terms instead of
  overwriting them with only location/schedule.
- Added offer→onboarding→`EmployeeCompensation` regression:
  - one compensation draft per employment;
  - exact salary 2,000,000 minor units;
  - currency/scheme preserved;
  - DRAFT + ONBOARDING source + approved case id.
- Verification: focused Offer/Onboarding/Talent/Payroll tests 10 tests /
  124 assertions; Pint, PHPStan and arch green.

Remaining T15: bonus mapping API/UI and training attempt source chain.
