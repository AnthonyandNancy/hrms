# T11 Payroll Runs

## T11a — run member persistence and preassignment

- Added `payroll_run_members` with unique `(run_id,user_id,member_role)` and
  roles PREPARER/REVIEWER/APPROVER/PUBLISHER/DISBURSER.
- Run creation writes the creator as PREPARER in the same transaction.
- Added `PayrollRunService::assignMember()` with actor membership check,
  reason, valid-from timestamp and audit evidence.
- Production `EloquentPayrollRunMembershipDirectory` now reads the member table
  first, retaining legacy actor-column fallback for historical runs.
- Run detail exposes members; preassigned reviewer membership is tested through
  the production directory.
- Verification: payroll suite 106 tests / 492 assertions; Pint, PHPStan and
  arch:test green.

Remaining T11: preassignment HTTP/actions surface, orthogonal publish and
disbursement states, close prerequisites, and blocked-run transition tests.
