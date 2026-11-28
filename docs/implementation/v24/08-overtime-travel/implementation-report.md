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
