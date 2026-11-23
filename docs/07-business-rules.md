# 07 Business Rules

This file records business rules that are binding for the reference
implementation. It is separate from the solution design: where the design
describes intent, this document fixes the executable conventions used by code,
tests, and reconciliation.

Module sections are owned by their domain. Later modules may append sections;
they must not silently alter another module's rules.

## Payroll

Status: Task 7A pure calculation core. This section describes gross-to-net,
statutory deductions, withholding, corrections, and the DEMO parameter
boundary. Lifecycle, approval, publication, and bank/tax export rules are
implemented by the payroll run lifecycle (Task 7B) and do not change the
arithmetic below.

### Money, rates, and rounding

- All monetary values are integer minor units (cents). PHP binary floating
  point is forbidden in `app/Modules/Payroll` and `app/Shared/Money`; a
  token-level test fails on float literals, casts, declarations, `floatval`,
  and native `round()` calls.
- Rates and statutory bases enter the domain as decimal strings with at least
  six decimal places. They are converted to scaled integers or exact
  `Decimal` values for arithmetic. No statutory rate, threshold, or base bound
  is hardcoded in the calculator.
- The default statutory rounding is half-up at the cent boundary. The
  supported modes are `HALF_UP`, `HALF_EVEN`, `DOWN`, and `UP`; the mode is a
  versioned payroll-context parameter. Rounding is applied only at documented
  monetary steps:
  1. prorated base salary to cents;
  2. each overtime earning to cents;
  3. each employee and employer social-insurance contribution to cents;
  4. each employee and employer housing-fund contribution to cents;
  5. cumulative tax to cents from the tax table;
  6. each payslip deduction/earning line derived from those amounts.
- Intermediate division and multiplication are carried at scale 6 (or greater
  as required by the operands) and are not rounded to cents until the step's
  documented boundary.
- A rounded result of zero is always represented as `0` / `0.00`. Negative
  zero is forbidden in storage, DTOs, and JSON.

### Calculation order

For one employment and one earning period, `PayrollCalculator` executes this
order. The order is part of the contract; changing it requires regression
fixtures and UAT-11 review.

1. **Base salary**: monthly base salary from the employment-terms snapshot.
2. **Attendance/leave proration**: non-paid absence reduces the base salary
   using the configured daily-rate divisor. Paid leave remains paid. Overtime
   is not part of proration.
3. **Allowances and one-time bonuses**: approved scheme and bonus inputs, in
   integer cents, each with a source.
4. **Overtime**: `overtime minutes / 60 * hourly rate * multiplier`; the
   multiplier defaults to `1.5` and is a parameter. The hourly rate uses the
   same versioned divisor as proration:
   - `ANNUAL_DAYS_365`: daily rate = monthly salary × 12 ÷ 365; hourly rate =
     daily rate ÷ 8.
   - `FIXED_21_75`: daily rate = monthly salary ÷ 21.75; hourly rate = daily
     rate ÷ 8.
5. **Gross**: the sum of all earning lines, including positive approved manual
   adjustments.
6. **Social insurance**: clamp gross to the rule's `[minBase, maxBase]`, then
   apply employee and employer rates separately. Bases and rates are versioned
   legal-entity/locality parameters.
7. **Housing fund**: clamp gross to the independent housing-fund
   `[minBase, maxBase]`, then apply employee and employer rates. A housing-fund
   base is never implicitly reused from social insurance.
8. **Taxable income**: `gross - employee SI - employee HF - special deductions
   - threshold`, floored at zero. Special deductions are approved declaration
   amounts and never reduce cash net on their own; they reduce the tax base
   only.
9. **Cumulative withholding**: see below. The tax table is applied to
   cumulative taxable income, not only the current month.
10. **Other deductions**: other approved deductions plus negative manual
    adjustments. Statutory deductions, tax, other deductions, and recoveries
    are emitted as separate traceable lines.
11. **Net**: `gross - employee SI - employee HF - current tax - other
    deductions`. A negative net is rejected unless the payroll context
    explicitly permits it; when permitted it is returned exactly (never
    clamped to zero) and flagged with `NET_BELOW_ZERO`.
12. **Employer cost**: `gross + employer SI + employer HF`. Employer
    contributions never reduce employee net.

`PayrollCalculationResult::assertBalanced()` enforces:
`gross = Σ earning lines`; `employee deductions = Σ deduction lines`;
`net = gross - employee deductions`; employer contributions equal their
employer-cost lines; and `employer cost = gross + employer SI + employer HF`.

### Proration divisors

Two versioned divisors are supported in V1:

- `ANNUAL_DAYS_365` (default): daily rate = `monthly salary × 12 ÷ 365`.
- `FIXED_21_75`: daily rate = `monthly salary ÷ 21.75`.

The divisor identifier is stored in the calculation context/snapshot. It is
not inferred from the calendar month and must be kept for recalculation of a
closed or corrected period. A full-attendance month always pays the full
monthly base salary, including February in a leap year; proration applies only
to unworked minutes. The conversion uses a standard 8-hour day (480 minutes),
and exact minutes are preserved until the documented cents boundary.

### Cumulative withholding (resident individuals)

The calculator is stateless and receives an immutable YTD opening snapshot for
the same tax year:

- `cumulative taxable = YTD taxable + current-period taxable`
- `cumulative tax = tax_table(cumulative taxable)`
- `difference = cumulative tax - YTD tax withheld`
- `current tax = max(0, difference)`
- `carry forward = min(0, difference)`

There is no in-year refund. If the recalculated cumulative liability is lower
than tax already withheld, current withholding is zero and the negative
difference is retained in `CumulativeTaxDetail.carryForwardCents` for review and
use in subsequent periods. The tax year must match the earning-period year; a
new tax year starts from a fresh YTD snapshot.

`CumulativeTaxDetail` carries the tax-table version, tax year, opening YTD
taxable and withheld amounts, current taxable, cumulative taxable, threshold,
special deductions, cumulative tax, current tax, and carry-forward. This is the
audit trail needed to explain a payslip tax figure.

### Correction runs and YTD

A published/closed run is immutable. A correction is a new run that references
the original and recalculates from the original input snapshot plus YTD, then
emits delta lines only:

- `correction gross delta = revised gross - original gross`
- `correction deduction delta = revised deductions - original deductions`
- `correction tax delta = revised tax - original tax`
- `correction net delta = revised net - original net`

Original plus correction totals must equal the revised payable amount exactly.
The YTD snapshot for the correction is the opening YTD before the original
run, so a full-year recomputation sees the same facts as the original and the
correction represents only the difference. The correction must not rewrite the
original run, its snapshot, its payslip, or its receipts.

### Time axes (binding)

Four times are kept separate throughout payroll DTOs and snapshots:

1. **earning period**: the period whose work is being paid;
2. **posting period**: the finance period receiving the cost;
3. **pay date**: when cash is expected/paid;
4. **cutoff timestamp**: when payroll input was frozen.

A January earning-period correction paid in February remains a January
earning-period correction with an explicit posting period and pay date; it
does not mutate the January closed run.

### Traceability

Every payslip line has a mandatory `sourceType` and `sourceId` and a unique,
increasing `sortOrder`. Statutory lines point to the versioned SI/HF rule or
tax-table version; salary, attendance, allowances, bonuses, deductions, and
manual adjustments retain their own source fact/identifier. A total without
traceable lines is invalid.

### DEMO-only parameters

All numeric tax brackets, quick deductions, thresholds, SI/HF rates, and base
bounds used by tests, seeders, or examples are **DEMO-ONLY**. They are synthetic
and must never be presented as authoritative statutory values for any
jurisdiction. Production values require a versioned, effective-dated parameter
set with legal-entity/locality scope and finance/legal approval evidence. The
calculator accepts only injected parameter objects; it owns no jurisdiction's
tax or contribution numbers.

### Explicit gaps for Task 7A

- No HTTP, database, migrations, lifecycle state machine, or receipt
  reconciliation is implemented in this slice.
- The calculator does not fetch employment terms, attendance, approval
  evidence, or YTD; Task 7B supplies immutable snapshots.
- Multi-locality insurance combinations (for example separate pension, medical,
  unemployment, injury, and maternity rules) are modeled as one injected SI
  rule in this slice. A later statutory-parameter expansion may split codes
  without changing the gross-to-net order or cent/rounding invariants.
- Tax filing, bank clearing, payslip PDF rendering, and parallel-run file
  comparison remain outside Task 7A.

## Time, attendance and leave (Task 6)

Status: implemented HTTP/operations slice. Authoritative domain code lives under
`backend/app/Modules/Time`; OpenAPI is `docs/openapi/openapi.yaml`.

### Business dates and immutable facts

- Raw `punch_records` are append-only evidence. They are never updated or
  deleted; a correction creates an `AttendanceCorrection` and a Workflow Case.
  Recalculation writes a new `attendance_days` projection and records the
  `rule_version` that produced it.
- A business date is a date in the legal entity's IANA timezone. Shift windows
  own overnight punches: a 22:00–06:00 shift's morning tail belongs to the
  shift start business date. `DATE(punched_at)` is never used.
- Persisted instants are UTC (`DATETIME(6)`); DATE columns carry business dates.
  Time arithmetic uses `CarbonImmutable` and the shared `Clock`.

### Canonical leave quantity

The ledger stores **signed integer minutes for every leave type**. Day display
is derived with the legal entity's standard day (default 480 minutes); it is
never stored as a float. The account direction is:

`GRANT +, RESERVE −, CONSUME −, RELEASE +, EXPIRE −, ADJUST ±, REVERSE ±`.

`balance = sum(signed quantities)` and, because RESERVE is already negative,
`available = balance` unless the leave type explicitly allows a negative
balance. `LeaveEquation::summarize` is the only balance equation implementation;
it returns per-account sums and `unexplained_difference`, which must be zero.

### Request, cancel and approval lifecycle

- Reserve-at-submit: `POST /v1/time/leave/requests` (and the brief alias
  `POST /v1/leave/requests`) creates a `LEAVE_REQUEST` Workflow Case and appends a
  `RESERVE` entry keyed by `(LEAVE_REQUEST, case_id)`.
- `POST /v1/time/leave/requests/{caseId}/cancel` appends `RELEASE` once, asks
  Workflow to withdraw the Case, and marks the local projection `CANCELLED`.
  Replaying cancellation returns `replayed: true` and does not double-refund.
- Overlapping submitted/approved requests for the same employment and leave type
  are rejected with `409 LEAVE_REQUEST_OVERLAP`. Concurrent reservations lock the
  open `leave_accounts` row and enforce the negative-balance policy inside the
  same database transaction.
- Approval/consume/reverse effects belong to Workflow; Time exposes
  `LeaveLedgerService` operations and never stores an approval status of its own.
  Workflow Cases are accessed only through `WorkflowCasePort`.

### API surface (all capability-guarded, tenant-scoped, no public routes)

Frontend-contract paths:

- `GET /v1/time/attendance?month=YYYY-MM` — self attendance month projection.
- `GET /v1/time/attendance/{employment_id}?from=&to=` — scoped attendance-day
  projection read.
- `POST /v1/time/punches/import` — idempotent raw punch batch import.
- `POST /v1/time/attendance/corrections` — correction Case request.
- `GET /v1/time/calendars/{legal_entity_id}?year=` and `GET /v1/time/shifts`.
- `GET /v1/time/leave/balances?as_of=` — self balance projection derived from the
  ledger.
- `GET /v1/time/leave/requests` and
  `POST /v1/time/leave/preview`.
- `POST /v1/time/leave/requests` and
  `POST /v1/time/leave/requests/{caseId}/cancel`.
- `GET /v1/time/leave/ledger?leave_type=<code>&as_of=` — drill-down with entries,
  ledger summary and running balance after each entry.

Brief aliases share the same controllers and scope checks:
`POST /v1/leave/requests`, `POST /v1/leave/requests/{caseId}/cancel`,
`GET /v1/leave/balances/{employment_id}?as_of=`, and
`GET /v1/leave/ledger/{employment_id}?leave_type_id=&as_of=`.

Capability mapping: reads use `time.attendance.read` / `time.leave.read`;
punch import uses `time.punch.import`; corrections use
`time.attendance.correct.request`; leave submission uses `time.leave.request`;
cancellation uses `time.leave.request.cancel`; ledger drill-down uses
`time.leave.ledger.read`; calendar/shift reads use `time.calendar.read` /
`time.shift.read`. A request without a matching capability is 403; an
employment outside the actor's SELF/MANAGED/legal-entity scope is 404 so other
tenants cannot be enumerated.

### Reconciliation command

```bash
php artisan ledger:reconcile --as-of=2026-11-23 [--tenant=1] [--rebuild]
```

- Recomputes each open leave account from append-only ledger entries effective on
  or before `--as-of`, using `LeaveEquation::summarize`, and compares it with the
  stored `leave_balances` projection.
- Prints accounts checked, ledger-entry count, projection mismatches, equation
  differences and per-account `DIFF`/`EQUATION` rows.
- Exit `0`: all projections and equations match. Exit `1`: any mismatch or
  unexplained equation difference. Exit `2`: invalid `--as-of` or `--tenant`.
- With `--rebuild`, mismatched projections are repaired through
  `LeaveBalanceService::project` and re-checked; exit 0 only if the re-check is
  clean. The command never updates or deletes ledger entries.
- `--date` is accepted as a backwards-compatible alias for `--as-of`; omitted
  `--as-of` defaults to the injected clock's today.

## Lifecycle

Status: Task 5B implementation. Lifecycle verticals are Case types whose
approved effects are the only path that mutates People employment facts.

### Case-owned state changes

- ONBOARDING, PROBATION_CONFIRM, ASSIGNMENT_CHANGE/TRANSFER, TERMS_CHANGE,
  OFFBOARD, CONTRACT_RENEW and REHIRE are closed effect handlers registered
  under `workflow.effect_types`. A controller never writes employment,
  assignment or terms rows directly.
- Case application status and effect status are separate. Approval appends a
  decision; the effect runs idempotently and records an EFFECTIVE intent. A
  replayed approval/effect for the same case does not create a second person,
  employment, assignment, terms version or checklist.
- Every lifecycle POST requires `Idempotency-Key`. The key covers the command
  payload and tenant/principal scope; a replay returns the original result.
- Effective ranges are half-open `[valid_from, valid_to)` with `9999-12-31`
  as the open end. Future-dated approvals may write the future row while
  as-of reads before the effective date keep returning the prior fact.
- Salary and terms changes append a new `employment_terms` version and end
  the previous version; history is never rewritten. Money is stored as
  integer minor units (`base_salary_minor`) with an ISO-4217 currency.
- Cross-entity transfers end the source employment and open a new employment
  for the same person in the target legal entity, preserving the configured
  seniority rule. The old row remains queryable.
- Probation confirmation requires the current status PROBATION and an
  effective date within the configured confirmation windows; confirmation
  moves the employment to ACTIVE.
- Rehire always creates a new employment for the same person, requires the
  previous employment to be terminated, and applies only the explicit
  `PRESERVE_ORIGINAL` or `REHIRE_DATE` seniority rule (never inferred).

### Offboarding and checklist

- Offboarding requires all required checklist tasks to be DONE or WAIVED with
  a non-blank reason; otherwise the effect fails with `CHECKLIST_INCOMPLETE`
  and no employment rows change.
- Employment and assignment termination, IAM session revocation and
  auth_version increment, Workflow delegation revocation, leave-balance
  capture, export cancellation and the EFFECTIVE intent commit in one
  transaction. A partial termination is forbidden.
- Leave balances are captured through the Time `LeaveBalanceReader` contract
  and attached to the offboarding evidence; they are not recalculated later.

### Talent thin slices

- Recruitment requisition and offer approvals are Workflow Cases with
  registered effect handlers. Offer acceptance is allowed only from APPROVED,
  is idempotent, supersedes other open offers for the candidate, and creates
  exactly one onboarding Case draft linked by offer_id.
- Candidate email/mobile contact hashes are derived with the tenant PII HMAC
  key; plaintext is encrypted at rest and excluded from audit payloads.
- A performance review is immutable once submitted. An appeal creates a new
  Workflow Case and records the original review values unchanged.
- Training capacity is enforced under a session row lock plus a unique
  (session, employment) constraint. Enrolment at or beyond capacity returns
  conflict `TRAINING_CAPACITY_REACHED`; completion records attendance and a
  result without changing capacity.

### Explicit gaps for Task 5B

- The no-op export task canceller is an integration seam until Reporting
  exports are implemented. Offboarding records that cancellation was invoked
  but cannot cancel a real export job yet.
- Workflow assignee resolution for lifecycle templates depends on IAM role
  membership; production seeds must create an HR_ADMIN assignee before
  submitting a case.
- Integrity checks are operator diagnostics using contracts and read-only
  SQL; they are not an API.
