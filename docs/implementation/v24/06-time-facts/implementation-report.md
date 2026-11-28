# T06 — Time monthly payroll fact versions

- **Status:** COMPLETE (review remediation applied)
- **Date:** 2026-11-28
- **Baseline:** `b2a5a63b13a6522936d4dfb6a53dec3cdb0b260f`
- **Commits:** `a5c90a199cb2b4d067495d4560dc517ec6684295` (T06) plus the review-remediation commit on top
- **Branch:** `v2.4-implementation`
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`, T06
- **Design:** `docs/FactoryHR_V2.4_需求与技术设计说明书.md` 5.1.5, 5.3.3, AC-08/10/20

## Scope

Implemented the immutable monthly Time fact version boundary without starting
T09 Payroll snapshot wiring. Payroll calculation and snapshot behaviour remain
unchanged; their existing live `attendanceForPeriod` preview path is untouched.

## Changed files

### Schema

- `backend/database/migrations/2026_11_28_000900_create_time_period_locks.php`
  - adds `time_period_locks`, one durable mutex row per touched month. All Time
    mutations, schedule writes, close and reopen lock this row inside the same
    transaction as their write.
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
    missing schedule, scheduled working day without a fact, unresolved
    attendance, unresolved leave/overtime approval, leave ledger net missing,
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
  - is the single Time period lock/closure gate. `withOpenPeriods()` and
    `lockAndAssertOpen()` lock the durable `time_period_locks` month mutex inside
    the caller's write transaction, then reject a CLOSED attendance period before
    consulting `PayrollPeriodLockReader`.
- `backend/app/Modules/Time/Services/LeavePolicyService.php`
  - publishes explicit leave payroll treatment (`PAID/PARTIAL/UNPAID`) and
    `paid_ratio_scaled` inside `accrual_json`; absent treatment is `UNRESOLVED`
    and blocks close instead of guessing from the leave type code.
- `backend/app/Modules/Time/Services/PunchService.php`,
  `AttendanceCalculator.php`, `AttendanceCorrectionService.php`,
  `LeaveRequestService.php`, `LeaveLedgerService.php`,
  `OvertimeRequestService.php`, `OvertimeSettlementService.php`,
  `TravelService.php`, `LeaveApprovalService.php`, `AttendanceOpsService.php`
  - all Time fact/ledger/request writers that can affect a monthly fact now lock
    the same durable month mutex inside the transaction that performs the write,
    and reject a CLOSED month with stable `PERIOD_CLOSED`.
  - `TravelService::recordExpense()` is explicitly **not** in the fact lock set:
    `travel_expenses` is a claim-only financial record and is never read by
    `time_payroll_fact_rows` or `closedFactForPeriod()`. It therefore does not
    participate in the Time fact month lock; only the travel request, approval,
    change, cancel and attendance-link lifecycle do.

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
     leave submission/approval/ledger writes/revoke, overtime apply/confirm/settle
     and travel request/approve/change/cancel/effect lock the month mutex and
     reject CLOSED periods with `PERIOD_CLOSED`. Travel expense claims are not
     monthly fact inputs and are deliberately outside this lock set.
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

## Independent review remediation (2026-11-28)

The independent review returned “Spec ❌ / Changes requested”. Every finding now
has a focused failing-test → GREEN mapping:

| # | Review finding | Regression test | Production fix |
|---|---|---|---|
| 1 | Cross-month leave repeated the whole case net in every month; a later-month revoke left the original month full | `test_leave_crossing_months_uses_each_months_effective_days_and_reversal`, `test_real_http_revoke_after_the_original_month_closed_reduces_the_reopened_fact` | `leaveFacts()` computes one signed case net from all `CONSUME + REVERSE` ledger rows and allocates it by cumulative working-day share across the application. There is no second reversal deduction inside a month. The real-path test uses the HTTP revoke route plus `LeaveRequestService`, moves the clock to December, reopens and closes November, and asserts v2 has zero leave instead of 960 |
| 2 | Scheduled working day with no attendance/leave/travel/explicit absence was silently omitted; closed month with no rows returned a zero export; completely empty month closed | `test_scheduled_working_day_without_any_fact_blocks_close`, `test_a_completely_empty_month_blocks_close_with_no_eligible_facts`, `test_open_reopened_and_hash_missing_facts_do_not_yield_a_payroll_export`, `test_second_active_employee_without_any_month_evidence_blocks_close` | New `SCHEDULE_WITHOUT_FACT` and per-active-employment `NO_ELIGIBLE_TIME_FACTS` blockers; every active employment must have at least one schedule/attendance/leave/overtime/travel evidence row, unless the calendar proves no working day or the as-of directory proves zero employments; `closedFactForPeriod()` returns `null` when the employment has zero rows |
| 3 | `SUBMITTED/IN_REVIEW` leave/overtime was skipped | `test_non_terminal_leave_and_overtime_requests_block_close` | Terminal rejects are ignored; every other non-approved state raises `LEAVE_APPROVAL_UNRESOLVED` / `OVERTIME_APPROVAL_UNRESOLVED` |
| 4 | Travel change approval checked only the original date range | `test_travel_change_approval_checks_the_proposed_month_after_the_original_was_opened` | `approve()` checks the pending proposed range and `applyPendingChange()` checks original + proposed before moving the link |
| 5 | Reopen/close and mutations lacked consistent transaction + row-lock semantics | `test_reopen_rolls_back_every_change_when_a_later_step_fails`, `test_month_mutex_rolls_back_a_write_when_its_transaction_fails`, `test_closed_month_locks_out_a_mutation_and_reopen_restores_the_same_mutex`, `test_leave_approval_service_rejects_a_closed_month_before_writing_consumption`, `test_real_service_path_rolls_back_ledger_and_month_mutex_with_its_transaction` | New durable `time_period_locks` month mutex (migration `2026_11_28_000900`). `PayrollFreezeGuard::withOpenPeriods()`/`lockAndAssertOpen()` lock that row inside the mutation transaction; every Time fact/ledger/request writer including `LeaveApprovalService` and the operational shift-schedule writer locks the same row; travel expense claims are excluded because they are not fact inputs. Close/reopen also lock the closure row. SQLite only serializes writers: these tests prove transaction rollback, closed-month rejection and reopen behavior, **not** true parallel locking. Real MySQL close-vs-mutation contention is unexecuted and belongs to T19 |
| 6 | `periodsForRange()` skipped February from Jan 31 | `test_periods_for_range_does_not_skip_february_after_january_31` | Iteration uses year/month integers, never `DateTime::modify('+1 month')` |
| 7 | Close/reopen idempotency was not unified | `test_close_and_reopen_use_required_idempotency_and_replay_stably` | New `TimeIdempotentCommand`: missing key → 422 `IDEMPOTENCY_KEY_REQUIRED`; replay → 200 + `Idempotency-Replayed: true`; reused key with different payload → 409 `IDEMPOTENCY_PAYLOAD_MISMATCH`; OpenAPI requires the header |
| 8 | REST/HOLIDAY attendance incorrectly demanded a schedule | `test_rest_day_attendance_without_schedule_is_not_mistaken_for_missing_shift` | Schedule blockers and `SCHEDULED_MINUTES_UNRESOLVED` apply only on working days |
| 9 | Route `shift_schedules` write diverged from calculator `ShiftAssignment` read | `test_real_schedule_route_recalculate_and_close_use_the_same_shift_source` | `AttendanceCalculator::shiftAssignment()` resolves a concrete `ShiftSchedule` first, then falls back to effective-dated `ShiftAssignment` |
| 10 | Punch batch import could partially succeed | `test_punch_import_is_all_or_nothing_when_a_later_item_is_invalid` | Full batch validation precedes a single transaction around all inserts |
| 11 | `reopened_from_version_id` pointed at the first reopened version, not vN-1 | `test_each_reopen_points_the_next_version_to_its_direct_predecessor` | Every new close stores the immediate latest version id |
| 12 | Missing coverage for overtime/travel/UNPAID/PARTIAL/cross-month/schedule-only/directory/hash/duplicate-overlap/full row snapshot | The 26 focused tests below | Coverage added; `factRows()` compares every persisted column on v1→v2 |
| 13 | Precheck returned a rolled-back, unqueryable `fact_version_id` | `test_incomplete_or_missing_schedule_facts_block_close_with_chinese_location` | The rolled-back id is removed; blocker evidence is returned inline as `precheck_json`, while version/row/closure counts stay zero |
| 14 | v1 snapshot compared only three columns | `test_reopen_keeps_v1_and_the_next_close_creates_v2` | `factRows()` snapshots all row attributes |

Focused result after the third review remediation:

```text
OK (33 tests, 187 assertions)
```

Adjacent Time/Payroll regression suite:

```text
OK (299 tests, 31646 assertions)
```

Full backend suite:

```text
OK (1542 tests, 59328 assertions)
```

Verification truthfulness notes:

- `TimeFactVersionTest` and the adjacent suite are executed commands, not
  projected counts.
- Pint, PHPStan, `arch:test`, `capability:audit`, OpenAPI lint and OpenAPI
  validate are executed.
- `pnpm gate:all` was intentionally not run.
- **No MySQL concurrency test was executed in this task.** The SQLite mutex
  tests can only demonstrate serialized/rollback behavior. A real concurrent
  MySQL `close` vs mutation race remains unverified until T19.

Formatting/static analysis:

```text
vendor/bin/pint --test      PASS (1359 files)
vendor/bin/phpstan analyse  [OK] No errors
php artisan capability:audit Capability audit OK
php artisan arch:test        Module boundaries OK
openapi-lint                 OK (293 paths, 376 operations, 3.1.0)
pnpm openapi:validate        valid (2 pre-existing health warnings)
```

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
- Concurrent close-vs-write protection is implemented through the durable
  `time_period_locks.month` mutex, but only SQLite tests ran here. SQLite
  serializes writers, so these are **not** concurrency evidence. Real MySQL
  parallel `close` vs Time mutation (and reopen vs close) verification is
  unexecuted and belongs to T19.
- Monthly close now checks every active employment at the first day of the
  month for at least one schedule/attendance/leave/overtime/travel evidence.
  A published calendar with no working days, or an as-of employment directory
  that proves zero active employments, remains the only empty-month exception.
