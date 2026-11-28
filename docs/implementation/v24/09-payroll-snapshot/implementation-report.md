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

## T09c — calculation honors blockers

- `PayrollEmployeeStatus` now has `BLOCKED`; snapshot blockers produce a
  `PayrollRunEmployee` row with zero monetary values and a stable reason, and no
  payslip is created for that employee.
- Added `PayrollRunStatus::CALCULATED_WITH_BLOCKERS`. A run with any blocked or
  failed employee transitions there instead of `CALCULATED`; the state machine
  only allows reopen/cancel from that state, so review/approve cannot proceed.
- Missing closed Time facts are blockers only when Time has actual evidence for
  the period. No Time-module data remains the legitimate full-month salary
  path; live attendance alone is never treated as frozen payroll input.
- Updated payroll integration fixtures to seed closed Time facts for attendance,
  paid/unpaid leave, overtime and termination cases.
- Added a regression proving missing closed facts produce `BLOCKED`,
  `CALCULATED_WITH_BLOCKERS`, no payslip and a rejected review transition.
- Verification: all Payroll feature + integration tests 104 / 481 assertions;
  Pint, PHPStan and arch green.

Remaining T09 work: benefit/performance frozen inputs, full employee scheme
resolution across every calculation path, and CALCULATED_WITH_BLOCKERS API/UI
exposure.

## T09d1 — expose blocked run and employee states

- OpenAPI payroll run status now includes `CALCULATED_WITH_BLOCKERS`; payroll
  employee status now includes `BLOCKED`.
- Generated TypeScript types and frontend normalisers/status arrays were
  synchronized.
- Chinese labels: run `计算有阻断`, employee `输入有阻断`; the payroll status
  filter includes the blocked run state.
- Review/calculate controls remain status-gated: blocked runs cannot review or
  approve and must be reopened for corrected inputs.
- Verification: OpenAPI lint/validate/generate/check; frontend typecheck/lint
  and payroll workspace tests 25 tests passed.

Remaining T09d: benefits/performance frozen input ports and real downstream
wiring after T14/T15.

## T09d2 — benefit and performance frozen input ports

- Added Payroll-owned `BenefitPayrollInputDirectory` and
  `PerformanceBonusDirectory` contracts with empty/default adapters. They are
  replaceable by T14/T15 without changing the snapshot schema or calculator.
- Snapshot v2 freezes `benefits` (employee/employer amounts, source, taxable,
  order) and `performance_bonuses` per employee.
- Calculation now turns frozen employee benefit amounts into explicit
  deductions and performance amounts into one-time taxable/non-taxable earning
  lines; both retain source type/id and sort order.
- Added a fake-adapter regression proving snapshot freezing and gross salary
  impact (base + bonus), while all payroll feature/integration tests remain
  green.
- Verification: Payroll suite 105 tests / 485 assertions; Pint, PHPStan and
  arch green. T14/T15 must replace the default adapters and rerun this suite
  before final benefit/performance acceptance.
