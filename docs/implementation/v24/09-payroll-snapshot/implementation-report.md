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
