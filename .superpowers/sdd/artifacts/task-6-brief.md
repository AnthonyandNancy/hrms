# Task 6 — Time, attendance, business calendar, leave ledger

## Context

Platform, IAM, People/Org, Workflow, and Lifecycle exist. Read `.superpowers/sdd/artifacts/interface-freeze.md`.

Authoritative sources: solution §6.5, §9.1–9.2, §9.6; plan §4.6; D-03, D-07, D-17; UAT-04, UAT-05, UAT-07 (leave part).

The core requirement: raw punches are immutable; attendance days are a recalculated projection; leave balances are a ledger, not a writable number. Business day is determined by legal-entity IANA timezone and business calendar, never `DATE(punched_at)`.

## Data model

- `legal_entity_calendars`: id, tenant_id, legal_entity_id, timezone IANA, workweek_json (e.g. [1,2,3,4,5]), created_at. One active per entity.
- `holidays`: id, tenant_id, legal_entity_id, date, name, type (`PUBLIC|COMPANY|MAKEUP`), source, version.
- `shift_templates`: id, tenant_id, legal_entity_id, code, name, start_local_time, end_local_time, crosses_midnight bool, break_minutes, late_grace_minutes, early_grace_minutes, standard_hours_decimal (6 dp string), version, valid_from, valid_to.
- `shift_assignments`: id, tenant_id, employment_id, shift_template_id, valid_from, valid_to, source.
- `punch_records`: id, tenant_id, employment_id, device_id nullable, punched_at_utc DATETIME(6), source_local_offset, source (`DEVICE|MANUAL|IMPORT`), raw_payload_hash, created_at. Append-only.
- `attendance_days`: id, tenant_id, employment_id, business_date, shift_template_id nullable, scheduled_minutes, worked_minutes, overtime_minutes, late_minutes, early_leave_minutes, absence_minutes, status (`NORMAL|LATE|EARLY|ABSENT|LEAVE|HOLIDAY|REST|INCOMPLETE`), rule_version, calculated_at, source_hash. Unique (employment_id, business_date).
- `attendance_corrections`: id, tenant_id, employment_id, business_date, requested_by, reason, corrected_punches_json, case_id, applied_at. Correction never edits punch_records.
- `leave_types`: id, tenant_id, code, name, is_statutory bool, unit (`DAY|HOUR`), requires_attachment bool, allow_negative bool, expiry_rule_json nullable, legal_basis nullable.
- `leave_accounts`: id, tenant_id, employment_id, leave_type_id, opened_on, closed_on nullable. Unique active per employment+type.
- `leave_ledger_entries`: id, tenant_id, employment_id, leave_type_id, account (`GRANT|RESERVE|CONSUME|RELEASE|EXPIRE|ADJUST|REVERSE`), quantity_units int (minutes for HOUR, hundredths of day for DAY — choose one canonical unit and document), business_date, effective_on, source_type, source_id, case_id nullable, reason, recorded_at, created_at. Append-only.
- `leave_requests` as Case subject; no approval status column here. A projection view/table may hold current state derived from decisions/effects; prefer query projection.
- `leave_balances` optional materialized projection with `employment_id, leave_type_id, as_of, granted, reserved, consumed, expired, adjusted, balance`; rebuildable.
- `overtime_records` (approved overtime via Case effect), `business_trip_records` if needed for conflict checks; keep thin.

Canonical leave quantity: **minutes as signed integers** for all types; day-based types convert using the legal entity's standard day (documented, e.g. 480 minutes) but display days. State this clearly and test conversions.

## Rules

- Legal entity timezone from IANA; convert punch UTC to local business day via shift window. Overnight shift: a punch after midnight belongs to the business date the shift started.
- Holidays and make-up workdays override weekday rules.
- Standard annual leave: cumulative service >=1 year 5 days, >=10 years 10 days, >=20 years 15 days. Statutory type cannot be configured below legal minimum; reject configuration that does.
- GRANT at accrual or opening; RESERVE on submitted request (or approval depending on policy — choose reserve-at-submit and document); CONSUME on final approval/effect; RELEASE on rejection/withdrawal; EXPIRE on expiry job; ADJUST only via approved Case with reason; REVERSE for corrections after consumption.
- Overbooking prevented under concurrency with DB transaction and negative-balance rule.
- Effective-dated rules are versioned; recalculating an old day uses the rule version recorded on it. New rules never silently rewrite history.
- Corrections: Case -> effect recalculates attendance_days for affected dates and appends ADJUST/REVERSE ledger entries; raw punches untouched.
- Leave calendar conflicts: overlapping leave for same employment rejected; leave vs approved overtime/business trip flagged.

## Endpoints

- `GET /v1/attendance/days?employment_id=&from=&to=`
- `GET /v1/attendance/my?from=&to=`
- `POST /v1/attendance/punches` (device/import; idempotent by raw hash)
- `POST /v1/attendance/corrections` (creates Case)
- `POST /v1/attendance/recalculate` (admin; date range; 202 async or sync small range)
- `GET /v1/leave/types`
- `GET /v1/leave/balances?employment_id=&as_of=`
- `GET /v1/leave/ledger?employment_id=&leave_type_id=&from=&to=`
- `POST /v1/leave/requests` (creates Case; reserves on submit)
- `POST /v1/leave/requests/{caseId}/cancel`
- `GET /v1/leave/requests/{caseId}`
- `POST /v1/time/calendars`, `POST /v1/time/holidays`, `POST /v1/time/shifts`, `POST /v1/time/shift-assignments`
- `GET /v1/time/conflicts?employment_id=&from=&to=`

All guarded (`time.*`), tenant-scoped, audited.

## Effect handlers

- `LEAVE_RESERVE`, `LEAVE_CONSUME`, `LEAVE_RELEASE`, `LEAVE_REVERSE`, `ATTENDANCE_RECALCULATE`, `OVERTIME_APPROVE`.
- Registered with Workflow EffectRegistry; idempotent by ledger unique key `(source_type, source_id, account)` or explicit unique.
- Leave approval failing to consume (insufficient balance due to concurrent use) => Effect FAILED with clear reason; case stays approved-not-effective; retry after adjustment.
- Offboarding final settlement reads outstanding balance; provide `LeaveBalanceService::outstanding(employmentId, asOf)` for payroll.

## Commands

- `php artisan time:seed-calendar --legal-entity=`
- `php artisan attendance:recalculate --from= --to= --employment=`
- `php artisan leave:accrue --as-of=` — idempotent grants per policy.
- `php artisan leave:expire --as-of=`
- `php artisan ledger:reconcile --date=` — for each account, opening + grants + adjustments - consumption - expiry ± reversals = closing; exit non-zero on unexplained difference.
- `php artisan time:rebuild-balances` — rebuild materialized balances from ledger and compare.
- `php artisan time:conflict-check --from= --to=`

## Tests (must pass)

Feature:
- `OvernightShiftTest`: 22:00–06:00 punch set maps to start business date; DST transition in a DST timezone (e.g. America/New_York) handled without duplicate/missing hour; Asia/Shanghai no DST.
- `HolidayCalendarTest`: public holiday, make-up workday, rest day; statutory annual leave floors (5/10/15) enforced.
- `AttendanceCorrectionTest`: correction creates Case; effect recalculates; raw punch unchanged; recalculation idempotent.
- `LeaveLedgerBalanceTest`: GRANT/RESERVE/CONSUME/RELEASE/EXPIRE/ADJUST/REVERSE all reflected; equation holds; no negative balance unless allowed.
- `LeaveConcurrencyTest`: two simultaneous requests for the last available day => one succeeds, one rejected/failed; no negative balance.
- `LeaveCancelTest`: cancel after approval => RELEASE or REVERSE correctly, no double refund.
- `ReversalTest`: consume then reverse restores balance exactly once; replaying reversal is no-op.
- `AccrualExpiryTest`: accrual idempotent on rerun; expiry at configured date; expired units cannot be consumed.
- `ConflictTest`: overlapping leave rejected; leave vs overtime/trip flagged.
- `AttendanceToPayrollContractTest`: exports a stable input DTO (employment, period, scheduled/worked/overtime/leave minutes) consumed by Payroll.
- `AuthorizationTest`: employee sees own only; manager sees team; HR sees entity; cross-tenant 404.
Unit:
- BusinessCalendarTest, ShiftWindowTest (cross-midnight, DST), LeaveEquationTest, StatutoryFloorTest, QuantityUnitTest.
Integration:
- `LedgerReconcileCommandTest` (clean => 0, injected corruption => non-zero), `BalanceRebuildTest`.
Contract:
- Attendance/leave schemas in OpenAPI.

## Constraints

- No `DATE(punched_at)` in business logic; no naive local timestamps.
- Ledger append-only; no updates/deletes. Corrections by new entries.
- Raw punch immutability enforced at model level.
- All date arithmetic via CarbonImmutable + legal entity timezone.
- No float for hours/quantities; integer minutes (and documented display conversion).
- Rules versioned; old attendance_days keep their rule_version.
- Update OpenAPI and the time/leave section of `docs/07-business-rules.md`.
- Run full backend suite, pint, phpstan, capability:audit, arch:test.

## Report

Write `.superpowers/sdd/artifacts/task-6-report.md` with files, exact command results, decisions, gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
