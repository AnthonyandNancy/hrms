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

## T11b1 — orthogonal publication and disbursement

- Added `payslip_status`, `disbursement_status`, `disbursement_reference`,
  `disbursement_registered_by` and `disbursement_registered_at` to payroll
  runs; historical PUBLISHED/PAID/CLOSED rows are backfilled.
- Publish now controls payslip visibility/YTD only; disbursement registration
  controls bank/receipt evidence only. They may run in either order.
- Lifecycle status remains as a compatibility projection: publish first gives
  PUBLISHED, disbursement first gives PAID; a later second action preserves the
  already-advanced status.
- Close requires both `payslip_status=PUBLISHED` and
  `disbursement_status=REGISTERED`; otherwise it returns
  `RUN_CLOSE_PREREQUISITES_MISSING`.
- SQLite sealed-row trigger now permits only the two YTD evidence flags when
  publication happens after disbursement; amounts remain immutable.
- Added lifecycle regression for publish→disburse→close, disburse→publish→close,
  and the close prerequisite failure.
- Verification: payroll suite 107 tests / 503 assertions; Pint, PHPStan and
  arch green.

Remaining T11: member preassignment HTTP/action projection and blocked-run
action/state tests.

## T11b2 — member preassignment HTTP and orthogonal action projection

- Added `POST /v1/payroll/runs/{id}/members` guarded by
  `payroll.run.prepare` on the PAYROLL_RUN anchor.
- The endpoint preassigns REVIEWER/APPROVER/PUBLISHER/DISBURSER with reason and
  returns the persisted member record.
- OpenAPI, generated TypeScript, page action manifest and action catalog now
  include `payroll.run.detail.assign-member`.
- Run detail includes `payslip_status`, `disbursement_status`, members and
  server-computed allowed actions. Publish is allowed only while unpublished;
  disbursement only while not registered; close only when both flags are
  complete.
- Added HTTP coverage for member assignment and allowed-action projection.
- Verification: payroll suite 108 tests / 509 assertions; Pint, PHPStan and
  arch green.

## T11b2 — member HTTP action and run-state projection

- Added `POST /v1/payroll/runs/{id}/members` and its generated action catalog
  entry `payroll.run.detail.assign-member`.
- PermissionResourceCatalog, action-input, generated PHP/TS/JSON manifests,
  route ledger and OpenAPI are synchronized.
- Run detail exposes payslip/disbursement flags and preassigned members;
  allowed actions now model publish-before-disburse, disburse-before-publish
  and close-only-when-both requirements.
- Added HTTP regression for member assignment and server-computed action
  projection.
- Verification: payroll 108 tests / 509 assertions; contract suite 39 tests /
  4,922 assertions; Pint, PHPStan and arch green.

Remaining T11: blocked-run action projection regression and frontend member
assignment form (T17 surface).

- Added an allowed-action regression for blocked and orthogonal states:
  blocked runs cannot review/approve/publish/close; approved runs expose
  publish and disbursement; fully published+registered runs expose close only.
