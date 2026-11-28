# T17 Frontend precision and self-service

## T17a — exact money and self payslip entry

- `formatMoney` now accepts `string | number | bigint` and uses BigInt
  cents-to-yuan arithmetic. Grouping uses Intl with a BigInt whole-yuan value;
  no float division is used. Currency symbols/prefixes and negative signs are
  deterministic, and unsafe non-integer numbers still render `—`.
- `parseYuanToCents` parses via BigInt and rejects values outside the safe
  integer range, in addition to rejecting >2 decimal places.
- `MoneyText` accepts string/number/bigint and derives the negative tone from
  the exact formatted value.
- Workspace self-service now embeds the real `PayrollPayslipsPanel` instead of
  routing through the management `/payroll` page. It only calls
  `/v1/payroll/payslips` for a user who has `payroll.payslip.read.self`; no
  `payroll.run.read` or `/v1/payroll/runs` request is made.
- `/v1/payroll/payslips` now returns `period` and `pay_date` with each own
  payslip so the self-service table does not need a management run list.
- Added regressions for BigInt/unsafe cents, negative/currency formatting,
  MoneyText bigint rendering, self payslip API scope/period and no management
  run request.
- Verification: format/MoneyText/WorkspaceHome 20 tests; payroll HTTP self
  payslip test; typecheck/lint planned with full gate.

Remaining T17: replace remaining capability-derived action bars with server
allowed_actions, exact action manifest coverage for detail pages, and broader
Chinese enum/error sweep.

## T17b — payroll action bar uses server allowed_actions only

- Removed the capability fallback from `PayrollActionBar`; permissions are now
  derived exclusively from the detail response's `allowed_actions`.
- Missing/omitted server actions fail closed and show a generic Chinese reason;
  internal capability names are never rendered.
- `PayrollRunDetailPage` no longer passes the client capability list to the
  action bar.
- Updated action-bar/detail tests to provide explicit server action keys for
  DRAFT/REVIEWED fixtures and to assert capability-free disabled reasons.
- Verification: payroll allowed-actions + workspace 16 tests; typecheck/lint
  green.

## T17c — remaining Payroll object actions

- Added API clients for correction creation and member assignment; `reopen` is
  part of the payroll action union and Chinese labels.
- Payroll detail now renders cancel/reopen/correct/assign-member controls only
  when the corresponding server `allowed_actions` key is present.
- Added one dialog with action-appropriate fields:
  - cancel/reopen reason;
  - at least two approver ids for reopen;
  - correction period + reason;
  - member user id, role and reason.
- Added frontend regressions asserting server-action visibility and exact
  cancel/correction payloads/idempotency keys.
- Verification: PayrollWorkspace 17 tests; typecheck/lint green.
