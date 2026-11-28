# T09 Payroll Snapshot v2

## T09a — execution compensation and per-employee scheme source

- Added Payroll-owned `CompensationVersionDirectory` and its Eloquent
  implementation. Only an effective `ACTIVE` version is a valid payroll input;
  DRAFT/PENDING/SUPERSEDED rows are ignored.
- Snapshot payload schema bumped to v2 and now contains:
  - the effective execution compensation id, contract version, base salary,
    currency, scheme id/version and parameter overrides;
  - the resolved per-employee scheme configuration used by calculation.
- Calculation prefers the snapshot compensation salary, then employment terms,
  then the run scheme base salary as a transitional fallback for legacy data.
- Per-employee rounding/proration/overtime configuration comes from the
  snapshot scheme rather than only the run scheme.
- Added a regression proving an ACTIVE compensation overrides employment terms
  and that its scheme/overrides are frozen in the snapshot.

Verification:
- T09a payroll snapshot/contract-chain suite: 8 tests / 33 assertions.
- Broader payroll adjacent suite: 21 tests / 84 assertions.
- Pint, PHPStan, arch:test green.

Remaining T09 work: closed Time fact blockers, employee-level scheme resolution
for all calculation paths, benefit/performance inputs, and
CALCULATED_WITH_BLOCKERS run transitions.

## T09b — closed Time fact evidence and missing-fact blocker

- Snapshot now calls `PayrollInputDirectory::closedFactForPeriod()` and, when
  available, freezes the closed fact version id/version/source hash and all
  scheduled/worked/leave/partial-leave/overtime/travel minute aggregates.
- When the period has no closed Time fact, the snapshot records an explicit
  `CLOSED_TIME_FACT_MISSING` blocker and marks attendance evidence as
  `live_fallback` or `missing`. The fallback is explicitly transitional; it is
  not treated as frozen payroll evidence.
- Added tests for both branches:
  - missing closed fact produces the blocker;
  - a closed fact version maps into the snapshot with no blocker and is traceable
    by fact version id/hash.
- Verification: payroll snapshot/contract suite 23 tests / 93 assertions;
  Pint, PHPStan and arch green.

Remaining T09 work: calculation must honor blockers, run status must support
`CALCULATED_WITH_BLOCKERS`, and benefit/performance inputs plus employee-level
scheme resolution must be completed.
