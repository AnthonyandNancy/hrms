# T06 — Time monthly payroll fact versions

- **Status:** COMPLETE
- **Date:** 2026-11-28
- **Baseline:** `b2a5a63b13a6522936d4dfb6a53dec3cdb0b260f`
- **Branch:** `v2.4-implementation`
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`, T06
- **Design:** `docs/FactoryHR_V2.4_需求与技术设计说明书.md` 5.1.5, 5.3.3, AC-08/10/20

## Scope

Implemented the immutable monthly Time fact version boundary without starting
T09 Payroll snapshot wiring. Payroll calculation and snapshot behaviour remain
unchanged; their existing live `attendanceForPeriod` preview path is untouched.

## Changed files

### Schema

- `backend/database/migrations/2026_11_28_000500_create_time_payroll_fact_versions.php`
  - `time_payroll_fact_versions`: `period`, `version`, `status`
    (`OPEN/CLOSING/CLOSED/REOPENED`), `source_hash`, `exception_count`,
    `precheck_json`, `generated_at`, `closed_at/by`, `reopened_at/by/reason`,
    `reopened_from_version_id`, timestamps.
  - `time_payroll_fact_rows`: `fact_version_id`, `employment_id`,
    `business_date`, optional range fields, integer-minute scheduled/worked/
    paid/partial/unpaid/overtime-pay/overtime-timeoff/travel columns,
    leave type/policy version, overtime category, travel mode, source type/id/
    revision/approval status/hash, `recorded_at`.
  - `attendance_period_closures.current_fact_version_id` nullable pointer.

### Models and contract

- `backend/app/Modules/Time/Models/TimePayrollFactVersion.php`
  - append-only version row; lifecycle-only state transitions; no mutation of
    period/version/hash/rows.
- `backend/app/Modules/Time/Models/TimePayrollFactRow.php`
  - model-level rejection of `update` and `delete`.
- `backend/app/Modules/Time/Contracts/PayrollTimeFactExport.php`
  - typed integer-minute closed-fact export with fact version, fact version id,
    hash, exception count, aggregate minutes and canonical daily/interval fact rows.
- `backend/app/Modules/Time/Contracts/PayrollInputDirectory.php`
  - added `closedFactForPeriod(int $employmentId, string $period): ?PayrollTimeFactExport`;
    existing `attendanceForPeriod` preview remains.

### Services

- `backend/app/Modules/Time/Services/TimeFactVersionService.php`
  - builds facts from `attendance_days`, approved leave request projections plus
    append-only `leave_ledger_entries`, confirmed `overtime_requests` with their
    single `overtime_settlements`, and active `travel_attendance_links`.
  - precheck blockers are returned as Chinese, source-locatable entries:
    missing schedule, unresolved attendance, unresolved leave approval/ledger,
    missing leave payroll treatment, unconfirmed overtime, missing overtime
    settlement, unresolved travel approval, duplicate sources and overlapping
    time occupation.
  - canonical hash covers the fixed schema, period and ordered facts with integer
    minutes and source identity.
  - close appends rows and a CLOSED version, updates the closure pointer, and
    records audit evidence.
  - reopen marks only the current version REOPENED and preserves all old rows;
    next close appends `version + 1`.
  - repeated close on a CLOSED month replays the same version and never inserts
    duplicate rows.
- `backend/app/Modules/Time/Services/AttendanceClosingService.php`
  - delegates close/reopen to the fact service and returns `fact_version`,
    `fact_version_id`, `source_hash`, `exception_count` alongside compatibility
    fields.
- `backend/app/Modules/Time/Services/AttendanceOpsService.php`
  - closing list now exposes current fact version, hash and exception count.
- `backend/app/Modules/Time/Services/PayrollInputDirectoryService.php`
  - implements `closedFactForPeriod`; returns `null` unless the closure is CLOSED
    and the pointed fact version is CLOSED with a hash. It never falls back to live
    attendance.
- `backend/app/Modules/Time/Services/PayrollFreezeGuard.php`
  - remains the single freeze gate and now also enforces Time's own CLOSED
    attendance period before consulting `PayrollPeriodLockReader`.
- `backend/app/Modules/Time/Services/LeavePolicyService.php`
  - publishes explicit leave payroll treatment (`PAID/PARTIAL/UNPAID`) and
    `paid_ratio_scaled` inside `accrual_json`; absent treatment is `UNRESOLVED`
    and blocks close instead of guessing from the leave type code.
- `backend/app/Modules/Time/Services/PunchService.php`,
  `AttendanceCalculator.php`, `AttendanceCorrectionService.php`,
  `LeaveRequestService.php`, `LeaveLedgerService.php`,
  `OvertimeRequestService.php`, `OvertimeSettlementService.php`,
  `TravelService.php`
  - all fact-changing entrypoints now call the shared `PayrollFreezeGuard` and
    reject a CLOSED month with stable `PERIOD_CLOSED` before writing.

### OpenAPI / generated contract

- `docs/openapi/openapi.yaml`
  - `AttendanceClosure` now documents `fact_version`, `fact_version_id`,
    `source_hash`, `exception_count`.
  - `AttendanceClosureRow` documents `current_fact_version_id`, `fact_version`,
    `source_hash`, `exception_count`.
  - `LeavePolicyCatalog` documents `payroll_treatment` and `paid_ratio_scaled`.
- `frontend/src/generated/api.ts` regenerated from the contract.
- `frontend/src/modules/time/attendance/api.ts` normalises and exposes the new
  closure metadata and command result fields.
- `docs/01-architecture.md` and
  `backend/app/Shared/Architecture/ModuleBoundaryScanner.php`
  - Time ownership updated for `travel_attendance_links`,
    `time_payroll_fact_versions`, `time_payroll_fact_rows`.

## Behaviour by requirement

1. **Close produces a fact version**
   - Creates one `time_payroll_fact_versions` row, ordered immutable
     `time_payroll_fact_rows`, canonical SHA-256 `source_hash`, non-blocking
     `exception_count` (late/early/absent attendance exceptions) and
     `precheck_json`.
2. **Missing/incomplete facts block**
   - A failing precheck throws a 409
     `TIME_FACT_PRECHECK_FAILED` with `blockers[]`, each containing a stable code,
     Chinese message, employment id, business date and source id. No closure and
     no CLOSED version are written.
3. **Close replay**
   - Repeating close on a CLOSED period returns the same version/hash with
     `replayed: true`; version and row counts remain unchanged.
4. **Reopen retains v1 and next close creates v2**
   - Reopen only changes statuses to REOPENED, keeps v1 rows byte-for-byte
     queryable, and points v2's `reopened_from_version_id` at the prior version.
5. **Closed mutation rejection**
   - Shift schedules, punches, attendance recalculation, correction request/effect,
     leave submission/ledger writes/revoke, overtime apply/confirm/settle and
     travel request/approve/change/cancel/effect reject CLOSED periods via the
     shared guard and return `PERIOD_CLOSED`.
6. **Payroll directory**
   - `closedFactForPeriod` reads only the CLOSED version and returns stable,
     typed data for the employment; a missing/open/reopened period returns `null`.
7. **Leave treatment**
   - Paid/partial/unpaid classification comes only from the effective leave
     policy version; a missing treatment blocks close with
     `LEAVE_PAYROLL_TREATMENT_UNRESOLVED`.
8. **No T09 changes**
   - Payroll `SnapshotService` and `CalculationService` were not modified.

## TDD evidence

Initial RED: `TimeFactVersionTest` failed because the fact tables/models/export
and directory method did not exist; HTTP close returned 201 without a
`fact_version`, and missing-schedule close was not blocked.

Intermediate RED for the extended freeze matrix:
`test_closed_period_blocks_punch_attendance_leave_overtime_and_travel_fact_mutations`
failed with “Expected mutation to be rejected with PERIOD_CLOSED” before the
service guards were added.

Final focused test run:

```text
OK (9 tests, 71 assertions)
```

Adjacent Time/payroll regression suite:

```text
OK (70 tests, 326 assertions)
```

## Verification

Executed and passing:

```text
git diff --check
backend: vendor/bin/pint --test
         PASS (1356 files)
backend: vendor/bin/phpstan analyse --no-progress
         [OK] No errors
backend: php artisan arch:test
         Module boundaries OK (4 existing non-blocking dynamic-table warnings)
backend: php artisan capability:audit
         Capability audit OK
backend: phpunit --testsuite Unit          OK (318 tests, 35005 assertions)
backend: phpunit --testsuite Feature       OK (1080 tests, 10626 assertions)
backend: phpunit --testsuite Integration   OK (24 tests, 109 assertions)
backend: phpunit --testsuite Contract      OK (65 tests, 13388 assertions)
backend: phpunit --testsuite Architecture  OK (31 tests, 77 assertions)
openapi: node deploy/scripts/openapi-lint.mjs
         OK (293 paths, 376 operations, 3.1.0)
openapi: pnpm openapi:validate
         valid (2 pre-existing health-operation warnings)
openapi: pnpm openapi:generate
         wrote frontend/src/generated/api.ts
openapi: pnpm openapi:check
         generated types current
frontend: pnpm typecheck
         PASS
```

`pnpm gate:all` was intentionally not run, per the task instruction.

## Incomplete / follow-up boundaries

- T09 must wire `closedFactForPeriod` into Payroll snapshots/calculation.
  Until then, Payroll continues to use the existing live attendance preview and
  no closed-fact linkage exists in `payroll_input_snapshots`.
- T07 owns the full Workflow leave effect, day-split net ledger and cancellation
  reversal semantics. This task consumes approved projections/ledger rows and
  blocks blocked/uncertain leave, but does not register Workflow effects.
- T08 owns overtime reversal, travel workspace completion and richer travel
  attendance modes. This task includes settled overtime and active travel links
  in the fact version with simple `ATTENDANCE_CREDIT` travel mode.
- T17/T18 own the full UI for fact-version visibility and exception drill-down;
  only compatibility fields and normalisers were added here.

## Risks

- The initial safe implementation blocks a leave close whenever an approved
  leave policy has no explicit payroll treatment. Existing seeded policies will
  therefore require a T07-compatible policy update before monthly close; this is
  deliberate fail-closed behaviour, not silent legacy classification.
- `exception_count` is a non-blocking attendance anomaly count
  (late/early/absent). Blocking precheck findings are returned as `blockers`
  and prevent a CLOSED version.
- The migration adds nullable `current_fact_version_id` without a foreign key to
  keep the existing closure row usable during forward-only rollout. Referential
  integrity is enforced by the service and the immutable version status check.
