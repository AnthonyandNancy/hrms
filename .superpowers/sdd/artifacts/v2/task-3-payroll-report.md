# Volume 2 Task 3 — Payroll sweep report

- Status: COMPLETE (Payroll scope); one unrelated full-repo gate remains red outside this ownership.
- Commit: `eda421baa23ac7ac0b918dc744341acfc0994d18` — `refactor(payroll): remove tenant and legal-entity scope (module-wide)`
- Branch: `feat/v2.1-remediation`

## What changed

- Removed `tenant_id`/`legal_entity_id` from every Payroll model, fillable/casts/docblock, and deleted `Models/Concerns/BelongsToTenant.php` (tenant global scope and auto-stamp are gone).
- Removed tenant/entity filters from run, scheme, item, statutory parameter, benefit, compensation, payslip, YTD, receipt, export and reconciliation queries. No filter was replaced with a hardcoded `1`.
- Removed `tenantId` and `legalEntityId` params from Payroll services, controllers, commands, ports and domain `PayrollContext`; updated every internal call site.
- `COMPANY` remains the full-company anchor; `LEGAL_ENTITY` is absent from Payroll.
- Statutory parameters and schemes are now company-wide; duplicate-run detection is period + run type; payroll population uses People `employmentIdsAsOf()` at period start and end.
- Currency/timezone come from `CompanyProfileReader::current()` in snapshot/calculation; business dates use `CompanyBusinessDate`.
- Payroll work is unchanged in money semantics: integer minor units, scaled decimal rates, rounding/proration/cumulative YTD, snapshot hash/append-only evidence, correction deltas and SOD/approval ordering were not altered.

## Scope files

The committed change is **77 explicit paths**: 54 Payroll app files (including omitted-from-sweep Payroll-owned Contracts/Domain/Service files), 15 feature tests, 2 integration tests, `PayrollTestCase`, `PayrollScenario`, `AllowAllAuthorizer` (test support call-site only), and 3 Payroll unit tests. `sweep/payroll.txt` had 44 paths; omitted Payroll-owned files edited and reported:
`Contracts/{PayrollApprovalEffectPort,PayrollCostDirectory,PayrollCostRow,PayslipWorkspaceDirectory}.php`,
`Domain/{PayrollCalculator,PayrollContext,RoundingConfig,SocialInsuranceRule}.php`,
`Services/{ReopenService,UnavailablePayrollCostDirectory}.php`.

## Verification (run in `backend`)

| Gate | Result |
|---|---|
| `rg -n 'tenant_id\|legal_entity_id\|LEGAL_ENTITY\|tenantId(\|legalEntityId'` over `app/Modules/Payroll` + Payroll tests/support | **0 matches** (exit 1) |
| `php artisan test --compact --filter='Tests\\Feature\\Payroll'` | **69 passed, 271 assertions** |
| `vendor/bin/phpunit tests/Feature/Payroll tests/Integration/Payroll tests/Unit/Modules/Payroll` | **155 passed, 30,499 assertions** |
| `php artisan arch:test` | PASS — “Module boundaries OK”; 4 pre-existing non-blocking warnings |
| `vendor/bin/phpstan analyse --no-progress` on Payroll app/tests | PASS — no errors |
| `vendor/bin/pint --test` on Payroll app/tests | PASS — 123 files |
| Full-repo `vendor/bin/phpstan analyse` | RED outside Payroll ownership (319 errors at final run, none in Payroll; concurrent Organization/People migration in flight) |
| Full-repo `vendor/bin/pint --test` | RED outside Payroll ownership (concurrent untouched files, e.g. People/IAM tests, line endings) |

The full-repo gates were also affected during the sweep by in-flight shared changes (`AuditRecorder`, `PeopleScenario`, `CompanyBusinessDate`, Organization scope contract). Payroll was re-run green after those landed.

## External call sites (not edited by Payroll)

- `app/Shared/Authorization/Contracts/PayrollRunMembershipDirectory.php` — shared owner changed to `isMember(userId,runId,capability)`, `runIdsForUser(userId,businessDate)`, `legalEntityForRun` deleted; Payroll adapter matches.
- `app/Modules/Workflow/Services/PayrollApprovalEffectHandler.php` — calls `applyApproval(runId, actorUserId)`; matches Payroll port.
- `app/Modules/IAM/Http/Controllers/WorkspaceController.php` — calls `recentForEmployment(employmentId)`; matches Payroll port.
- `app/Modules/Reporting/{Providers/ReportingServiceProvider,Services/ProjectionBuilder,Services/MetricQueryBuilder}.php` — consume `PayrollCostDirectory::available()/unavailableReason()` and `PayrollCostRow` without legal-entity fields; adapted by Reporting owner.
- `tests/Fakes/FakePayrollRunMembershipDirectory.php`, `tests/Feature/Authorization/AuthorizationTestSupport.php` — Shared/IAM test fakes; outside Payroll ownership.
- Cross-module contracts consumed by Payroll and changed by their owners: `AuditRecorder`, `SensitiveAccessAuditor`, `Principal`, `PeopleDirectory`, `EmploymentTermsDirectory`, `PayrollInputDirectory`, `PayrollApprovalWorkflow`, `WorkflowCaseGateway`, `CompanyBusinessDate`.
