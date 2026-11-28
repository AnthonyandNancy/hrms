# T08 Overtime / Timeoff / Travel

## T08a — append-only timeoff ledger and travel conflict guard

- Added `timeoff_ledger_entries`:
  - employment, optional unique overtime settlement, entry type
    EARN/USE/REVERSE/EXPIRE/ADJUST, signed minutes, effective date, reason,
    actor and recorded timestamp;
  - append-only Eloquent guard rejects update/delete;
  - migration backfills existing TIMEOFF settlements into EARN entries.
- TIMEOFF settlement now atomically writes one EARN ledger entry and stores the
  settlement's `leave_ledger_entry_id`; replay cannot duplicate credit.
- `timeoffBalances` now sums the ledger (`SUM(quantity_minutes)`) rather than
  deriving a balance directly from settlement rows.
- Travel request precheck now rejects overlaps with:
  - active leave projections;
  - APPROVED/CONFIRMED overtime;
  - recorded attendance with worked minutes.
  Conflicts return `TRAVEL_TIME_CONFLICT` with a specific conflict type.
- Payroll AttendanceSummary now carries frozen `travel_minutes`; paid minutes
  include travel credit, and FORMULA_LIMITED rules can read `travel_minutes`.
- Added regressions for append-only ledger, TIMEOFF credit, payroll travel
  formula, travel/overtime and travel/attendance conflicts.
- Verification: Time+Payroll 450 tests / 32,013 assertions; Pint/PHPStan/arch
  green.

Remaining T08: overtime request unified Workflow approval/effect (currently
request creation still directly APPROVED), travel change/reversal full flow and
API allowed_actions wiring.

## T08b — overtime Workflow approval and policy-constrained settlement

- Overtime application now opens a Workflow `OVERTIME_REQUEST` case and stores
  `case_id`; `status` starts as SUBMITTED instead of directly APPROVED.
- Added `OVERTIME_REQUEST → overtime.request` template mapping and seeded
  template, plus `overtime.request.approve` object action.
- Added `POST /v1/time/overtime/requests/{id}/approve`:
  - `time.overtime.manage`, MANAGED anchor, idempotent CommandRunner;
  - decides the Workflow case and only then projects APPROVED/REJECTED;
  - returns server `allowed_actions`; APPROVE exposes CONFIRM, rejection does not.
- CONFIRM remains separate and still requires the approved request, then
  materialises one overtime record with its policy snapshot.
- Overtime settlement now reads the confirmed policy snapshot and rejects
  PAY/TIMEOFF when `allow_pay`/`allow_timeoff` is false
  (`OVERTIME_METHOD_NOT_ALLOWED`); TIMEOFF still writes exactly one ledger EARN.
- Updated OpenAPI/action manifest/page APIs/permission catalog/ledger and all
  affected tests to approve through Workflow before confirmation.
- Verification: Time suite 239 tests / 1,132 assertions; targeted workflow,
  settlement, contract/parity tests green; Pint/PHPStan/arch green.

## T08c — overtime approval UI and list allowed_actions

- `/v1/time/overtime/requests` now injects per-row server `allowed_actions`,
  preserving projection evidence while allowing the list to render actions.
- Overtime object-action catalog exposes `overtime.request.approve` only for
  SUBMITTED/IN_REVIEW; CONFIRM is now allowed only for APPROVED.
- OvertimeTravel workspace:
  - request rows expose 审批加班 only when the server row says so;
  - manager can approve/reject with an optional comment and stable idempotency
    key;
  - confirmation buttons consume `overtime.request.confirm` from the server
    allowed_actions rather than capability/status inference.
- Added frontend regression for approval payload/key and read-only action hiding.
