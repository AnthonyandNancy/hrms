# Volume 2 Task 3 — Time module sweep report

## Scope

- Owned files: every Time-owned production file and Time test/support file under
  `backend/app/Modules/Time/**`, `backend/tests/Feature/Time/**`,
  `backend/tests/Unit/Time/**`, `backend/tests/Support/Time/**`.
- Explicit manifest minimum: 50 files from
  `.superpowers/sdd/artifacts/v2/sweep/time.txt`.
- Module-wide Time cleanup additionally covered Time Contracts, Adapters,
  TimeRequestScope, Providers, the four Time controllers omitted from the
  manifest, Time reader services, and every Time test/support file needed to
  keep the suite compiling.
- No file outside the Time module or Time tests/support was edited.

## What changed

1. Removed `tenant_id`, `legal_entity_id`, `tenantId`, `legalEntityId`, and
   `LEGAL_ENTITY` from Time runtime code. Filters and payload keys are deleted,
   never replaced by a hardcoded 1.
2. Time contracts are company-scoped:
   - `TimeActor` drops `tenantId` and `legalEntityIds`; `canReadEmployment()`
     evaluates SELF/MANAGED and treats COMPANY/HR/ADMIN as whole-company.
   - `EmploymentDirectory`/`EmploymentSnapshot` drop tenant and legal-entity
     fields; People calls use the tenant-free signatures.
   - `LegalEntityCalendarDirectory` keeps its historical name but exposes the
     single company calendar: `timezone()`, `standardDayMinutes()`,
     `workweek()`.
   - `PayrollInputDirectory`, `LeaveBalanceReader`, `LeaveLiabilityDirectory`,
     `AttendanceWorkspaceReader`, `LeaveRequestReader`, and
     `WorkflowCasePort` drop tenant arguments; internal call sites updated.
3. `BusinessCalendar` is a single-row company calendar. `AttendanceCalculator`,
   leave ledger/balance/request services, attendance ops/closing/correction,
   overtime, payroll projection, punch, shift, and travel services are
   company-scoped.
4. Time controllers now resolve business dates through the company profile
   timezone and shared clock (`TimeRequestScope::today()/resolveDate()`), not a
   tenant id and not `TenantBusinessDate`.
5. `CalendarAdminController::publish()` is the route's company-level publish
   endpoint, with no `{legal_entity_id}` parameter or Organization legal-entity
   lookup. Calendar/holiday payloads and audit payloads no longer expose
   entity ids.
6. Travel policies, expenses, and business trips lose legal-entity columns;
   expense visibility is employment-scoped, with COMPANY scope meaning the
   whole company (no employment predicate), MANAGED meaning managed
   employments, SELF meaning self.
7. Attendance closings and devices are company-level reads: SELF/MANAGED
   actors receive 404 rather than a widened company listing.
8. Console commands are company-scoped; `ledger:reconcile` drops `--tenant`
   and reconciles the single company. `time:seed-calendar` and
   `time:seed-leave-types` drop tenant options and tenant filters.
9. Audit calls adapt to the Shared tenant-free `AuditRecorder`; sensitive
   access adapts to the tenant-free `SensitiveAccessAuditor`.
10. Tests: cross-tenant isolation assertions were rewritten as
    cross-DEPARTMENT / out-of-scope-employment isolation (SELF sees only self,
    MANAGED sees only managed employments, COMPANY sees the company, unknown
    employment ids 404). Tenant-only console cases were replaced by
    empty-company/idempotent-catalog cases.

## Verification (real output)

- `rg -n 'tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\(|legalEntityId|tenantId|TenantBusinessDate'` over
  `backend/app/Modules/Time`, `backend/tests/Support/Time`,
  `backend/tests/Feature/Time`, `backend/tests/Unit/Time`: **0 hits** (rg exit 1).
- `artisan arch:test`: **Module boundaries OK** (4 pre-existing non-blocking
  Platform dynamic-table warnings).
- Time tests (Feature + Unit): **136 passed, 524 assertions**.
- Required Artisan filter `--filter='Feature.Time'`: **98 passed, 442 assertions**.
- PHPStan scoped to `app/Modules/Time` + Time tests/support: **No errors**.
- Pint scoped to Time production + Time tests/support: **PASS, 113 files**.
- Full PHPStan/Pint remain red on files outside Time (concurrent People/
  Platform/Workflow edits); no Time finding appears in either global run.

## Cross-file call sites adapted (owned by other modules, not edited)

Other modules were already updated by their owners to the tenant-free Time
contracts at the time of this sweep; the current tree calls the new shapes:

- `App\Modules\IAM\Auth\TimeActorBridge` constructs the new `TimeActor`
  (IAM-owned).
- `App\Modules\IAM\Http\Controllers\WorkspaceController` calls
  `currentMonth(int,string)`, `outstanding(int,string)`,
  `forEmployment(int,?string)`, and calendar `timezone()` (IAM-owned).
- `App\Modules\Lifecycle\EffectHandlers\OffboardEffectHandler` calls
  `outstanding(int,string)` (Lifecycle-owned).
- `App\Modules\Payroll\Services\SnapshotService` calls
  `attendanceForPeriod(int,string)` / `hasRecordedAttendance(int,string)`
  (Payroll-owned).
- `App\Modules\Reporting\Services\ProjectionBuilder` calls `balances(string)`
  (Reporting-owned).

Workflow Contracts were outside the Workflow sweep owner's editable list; Time's
adapter consumes the tenant-free `CaseCommandPort::find(int)` /
`WorkflowPrincipal` current shape. Time integration tests for the real Workflow
adapter pass.
