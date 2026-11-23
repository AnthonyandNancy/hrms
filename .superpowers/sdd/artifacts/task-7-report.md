# Task 7B Report — Payroll Run Lifecycle

## Status

COMPLETE, with the documented gaps below. Implemented in the isolated worktree
`D:\code\ai\factoryHR\.worktrees\task-7b-payroll` on branch
`feat/task-7b-payroll`.

**Important verification note:** an earlier run of this branch used
`backend/vendor` as a directory junction to the main checkout's `vendor/`.
Composer's generated `$baseDir` therefore resolved `App\...` to the wrong
worktree. Those earlier green results were invalid. The junction was replaced
with a real vendor copy inside this worktree, Composer autoload was regenerated
against this worktree, and every gate below was re-run from this isolated tree.
The main checkout was not edited (an accidental OpenAPI edit there was
reverted).

## Baseline and commits

Baseline read for the task: `062c68d` (Task 7A payroll calculator merge).

| Commit | Slice |
|---|---|
| `fdae802` | `feat(payroll)`: schema, immutable models and lifecycle core services |
| `3b21287` | `fix(payroll)`: static analysis and publication/YTD transaction ordering |
| `0391a22` | `feat(iam)`: payroll close, correction, reopen and parameter capabilities |
| `b0f6a1e` | `feat(payroll)`: lifecycle services, approval effect, commands and HTTP API |
| `2012ebe` | `test(payroll)`: lifecycle, SOD and immutability coverage; date normalization |
| `5e8b824` | `fix(payroll)`: isolated autoload, module boundaries, correction/YTD semantics |
| `95cded2` | `chore(payroll)`: Pint formatting and latest-version snapshot selection |
| `88dfc0f` | `test(payroll)`: HTTP lifecycle, approval effect and export guarantees |
| `78e1c21` | `feat(payroll)`: controlled reopen, parameter versioning docs and tests |
| `096c704` | `test(payroll)`: Time and People contract integration coverage |
| `b62b779` | `feat(payroll)`: mark-paid evidence / manual confirmation requirement |
| `adfff03` | `test(payroll)`: reconciliation, parallel-check and demo-seed commands |
| `83b0dde` | `docs(payroll)`: Task 7B lifecycle report |
| `25fc335` | `feat(payroll)`: approval routed through Workflow Case/Decision/Effect |

All commits are on `feat/task-7b-payroll` in the task worktree. No work was
performed in the main checkout.

## Files

### Migrations

- `backend/database/migrations/2026_11_23_200000_create_payroll_run_tables.php`
  creates payroll items, schemes, scheme items, runs, run employees, input
  snapshots, adjustments, payslips, payslip lines, statutory parameters, tax
  brackets, YTD, and receipts, with SQLite immutability triggers for snapshots,
  published payslips/lines, sealed run employees, sealed financial columns, and
  closed runs.
- `backend/database/migrations/2026_11_23_200001_add_payroll_salary_to_employment_terms.php`
  additively adds `base_salary_cents` and `salary_currency` to the People-owned
  `employment_terms` table. Salary must be as-of and versioned; storing it on
  the main employment row would corrupt historical payroll. This is a
  forward-only additive migration, not a runtime cross-module read.

### Payroll enums and models

- `backend/app/Modules/Payroll/Enums/PayrollRunStatus.php`
- `backend/app/Modules/Payroll/Enums/PayrollRunType.php`
- `backend/app/Modules/Payroll/Enums/PayrollEmployeeStatus.php`
- `backend/app/Modules/Payroll/Enums/PayrollItemType.php`
- `backend/app/Modules/Payroll/Enums/PayrollCalculationType.php`
- `backend/app/Modules/Payroll/Enums/PayrollParameterSet.php`
- `backend/app/Modules/Payroll/Enums/PayrollReceiptType.php`
- `backend/app/Modules/Payroll/Models/Concerns/BelongsToTenant.php`
- `backend/app/Modules/Payroll/Models/PayrollItem.php`
- `backend/app/Modules/Payroll/Models/PayrollScheme.php`
- `backend/app/Modules/Payroll/Models/PayrollSchemeItem.php`
- `backend/app/Modules/Payroll/Models/PayrollRun.php`
- `backend/app/Modules/Payroll/Models/PayrollRunEmployee.php`
- `backend/app/Modules/Payroll/Models/PayrollInputSnapshot.php`
- `backend/app/Modules/Payroll/Models/PayrollAdjustment.php`
- `backend/app/Modules/Payroll/Models/Payslip.php`
- `backend/app/Modules/Payroll/Models/PayslipLine.php`
- `backend/app/Modules/Payroll/Models/StatutoryParameter.php`
- `backend/app/Modules/Payroll/Models/TaxBracket.php`
- `backend/app/Modules/Payroll/Models/PayrollYtd.php`
- `backend/app/Modules/Payroll/Models/PayrollReceipt.php`

### Payroll services and ports

- `backend/app/Modules/Payroll/Services/PayrollStateMachine.php`
- `backend/app/Modules/Payroll/Services/SnapshotService.php`
- `backend/app/Modules/Payroll/Services/TaxParameterResolver.php`
- `backend/app/Modules/Payroll/Services/CalculationService.php`
- `backend/app/Modules/Payroll/Services/ApprovalService.php`
- `backend/app/Modules/Payroll/Services/PayrollYtdService.php`
- `backend/app/Modules/Payroll/Services/CorrectionService.php`
- `backend/app/Modules/Payroll/Services/ReopenService.php`
- `backend/app/Modules/Payroll/Services/ExportService.php`
- `backend/app/Modules/Payroll/Services/ReconciliationService.php`
- `backend/app/Modules/Payroll/Services/ReceiptReconciliationService.php`
- `backend/app/Modules/Payroll/Services/PayrollRunService.php`
- `backend/app/Modules/Payroll/Services/PayrollParallelCheckService.php`
- `backend/app/Modules/Payroll/Services/PayrollApprovalEffectService.php`
- `backend/app/Modules/Payroll/Contracts/PayrollApprovalEffectPort.php`
- `backend/app/Modules/Payroll/Providers/PayrollServiceProvider.php`

### Cross-module contracts (owned by Time, People, Workflow)

- `backend/app/Modules/Time/Contracts/PayrollAttendanceExport.php`
- `backend/app/Modules/Time/Contracts/PayrollInputDirectory.php`
- `backend/app/Modules/Time/Services/PayrollInputDirectoryService.php`
- `backend/app/Modules/Time/Providers/TimeServiceProvider.php`
- `backend/app/Modules/People/Contracts/EmploymentTermsSnapshot.php`
- `backend/app/Modules/People/Contracts/EmploymentTermsDirectory.php`
- `backend/app/Modules/People/Services/EmploymentTermsDirectoryService.php`
- `backend/app/Modules/Workflow/Contracts/PayrollApprovalWorkflow.php`
- `backend/app/Modules/Workflow/Services/PayrollApprovalWorkflowService.php`
- `backend/app/Modules/Workflow/Services/PayrollApprovalEffectHandler.php`

### HTTP controllers

- `backend/app/Modules/Payroll/Http/Controllers/PayrollCatalogController.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollPayslipController.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollStatutoryParameterController.php`

### Commands

- `backend/app/Modules/Payroll/Console/SeedPayrollParametersCommand.php`
- `backend/app/Modules/Payroll/Console/PayrollParallelCheckCommand.php`
- `backend/app/Modules/Payroll/Console/PayrollReconciliationCommand.php`

### Wiring and IAM

- `backend/bootstrap/providers.php`
- `backend/routes/api.php`
- `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`
- `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php`
- `backend/app/Modules/IAM/Http/Middleware/VerifyCsrfHeader.php` (explicit
  testing-only seam for the bound-principal harness; real-session CSRF tests
  remain green)
- `backend/app/Modules/People/Providers/PeopleServiceProvider.php`
- `backend/app/Modules/Workflow/Providers/WorkflowServiceProvider.php`
- `backend/app/Modules/Workflow/Services/NullEffectHandler.php`

### Tests

- `backend/tests/Support/PayrollScenario.php`
- `backend/tests/Support/PayrollTestCase.php`
- `backend/tests/Feature/Payroll/PayrollRunLifecycleTest.php`
- `backend/tests/Feature/Payroll/PayrollSnapshotTest.php`
- `backend/tests/Feature/Payroll/CorrectionRunTest.php`
- `backend/tests/Feature/Payroll/PayrollReopenTest.php`
- `backend/tests/Feature/Payroll/WorkflowPayrollApprovalTest.php`
- `backend/tests/Feature/Payroll/PayrollApprovalWorkflowAdapterTest.php`
- `backend/tests/Feature/Payroll/PayrollHttpLifecycleTest.php`
- `backend/tests/Feature/Payroll/PayrollExportTest.php`
- `backend/tests/Feature/Payroll/MarkPaidEvidenceTest.php`
- `backend/tests/Feature/Payroll/PayrollCommandTest.php`
- `backend/tests/Integration/Payroll/TimeToPayrollIntegrationTest.php`
- `backend/tests/Integration/Payroll/PeopleToPayrollIntegrationTest.php`
- `backend/tests/Feature/RetentionTest.php` (adapted to the real payroll
  schema that now owns `payslips`/`payroll_runs`)

### Documentation

- `docs/openapi/openapi.yaml` (25 payroll path operations added with Node
  `yaml`; path parameters declared; Redocly validation passes)
- `docs/07-business-rules.md` (Task 7B lifecycle, approval evidence, snapshot
  hashing, corrections, exports, parameter versioning, commands, and gaps)

## Endpoints

All are under `/v1/payroll` and capability-guarded; no public payroll route
exists.

- `GET/POST /v1/payroll/items`
- `GET/POST /v1/payroll/schemes`
- `GET/POST /v1/payroll/runs`
- `GET /v1/payroll/runs/{id}`
- `GET /v1/payroll/runs/{id}/employees`
- `GET /v1/payroll/runs/{id}/reconciliation`
- `POST /v1/payroll/runs/{id}/lock-inputs`
- `POST /v1/payroll/runs/{id}/calculate`
- `POST /v1/payroll/runs/{id}/review`
- `POST /v1/payroll/runs/{id}/approve`
- `POST /v1/payroll/runs/{id}/publish`
- `POST /v1/payroll/runs/{id}/mark-paid`
- `POST /v1/payroll/runs/{id}/close`
- `POST /v1/payroll/runs/{id}/cancel`
- `POST /v1/payroll/runs/{id}/reopen`
- `POST /v1/payroll/runs/{id}/corrections`
- `GET /v1/payroll/runs/{id}/exports/bank`
- `GET /v1/payroll/runs/{id}/exports/tax`
- `GET /v1/payroll/payslips` (self only)
- `GET /v1/payroll/payslips/{id}` (self only; 404 for another employee)
- `GET/POST /v1/payroll/statutory-parameters`

High-risk transitions (`review`, `approve`, `publish`, `mark-paid`, `close`,
`reopen`, `corrections`, exports, parameter creation) require step-up MFA in
production. The payslip list/detail use `payroll.payslip.read.self` and return
404, not 403, for another employee's payslip.

## Decisions

1. **Latest calculator reused, not rewritten.** `CalculationService` only
   resolves persisted facts and persists results; `PayrollCalculator` remains
   the sole arithmetic engine. No float, native `round()` or `abs()` exists in
   Payroll/Money (covered by the existing `NoFloatTest` over the whole module).
2. **Snapshot first, calculate second.** `input_hash` is a SHA-256 over
   recursively key-sorted canonical JSON of per-employment payload hashes plus
   run/scheme/rule identity. `snapshot_version` is deliberately not part of
   the hash, so a reopen with unchanged facts is rejected as
   `INPUT_HASH_UNCHANGED`, and a changed fact produces a different hash.
3. **Corrections are delta-only.** The baseline is the original payslip and
   employee totals. Prior correction rows stay separate payable rows and are
   not folded into the baseline, preventing double counting. All published
   corrections plus the original must reconcile against payslip totals.
4. **YTD folds once at publication.** The YTD opening for a correction is the
   original employee's opening YTD, so the revised calculation uses the same
   facts as the original. Publication folds the signed run/employee delta into
   `payroll_ytd` and marks `ytd_applied`; repeated publication is a no-op.
5. **Approval evidence is a real Workflow case/effect.** Payroll talks only to
   `Workflow\Contracts\PayrollApprovalWorkflow`; Workflow's
   `workflow.payroll.publish` handler calls
   `Payroll\Contracts\PayrollApprovalEffectPort`. The effect is idempotent and
   fails closed on unreviewed runs. SOD is enforced in the payroll service and
   separately by IAM capability conflicts.
6. **Module ownership was corrected after the isolation bug.** Payroll's
   provider no longer imports `Time\Services`, `People\Services` or
   `Workflow\Services`. Time binds `PayrollInputDirectory`, People binds
   `EmploymentTermsDirectory`, and Workflow binds
   `PayrollApprovalWorkflow`/the effect handler. `arch:test` is green.
7. **Deletion triggers were removed only for retention.** Model-level and
   financial-field immutability remain. A published payslip row and its run may
   be deleted only by the Platform retention service after its retention
   period and legal-hold check; `RetentionTest` covers this contract.
8. **No direct Time default.** If Time has no `attendance_days` or `CONSUME`
   ledger evidence for a period, the snapshot records zero scheduled minutes
   and the calculator pays a full month. This is a documented reference-build
   behavior, not a production default.
9. **Mark-paid evidence is mandatory.** A receipt/reconciliation reference or
   a manual-confirmation reason of at least 10 characters is required and
   audited.
10. **DEMO parameters are synthetic.** `payroll:seed-parameters` writes
    explicitly DEMO-labelled values and still requires `--approved-by` and
    evidence; it is not legal advice or a statutory source.

## Gate results (isolated worktree, honestly measured)

All commands run from
`D:\code\ai\factoryHR\.worktrees\task-7b-payroll\backend` with
`/d/code/ai/factoryHR/.tools/php` prepended to `PATH`.

```text
php artisan test --colors=never
Tests:    621 passed (33622 assertions)
Duration: 35.71s
```

```text
vendor/bin/pint --test
PASS  665 files
```

```text
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
[OK] No errors
```

```text
php artisan capability:audit
Capability audit OK.
```

The audit prints warnings for routes without a negative-test manifest entry
(pre-existing routes as well as the new payroll routes). It is a non-blocking
warning-level check by design.

```text
php artisan arch:test
Module boundaries OK.
```

13 non-blocking warnings remain, all pre-existing Platform dynamic-table
warnings; there are zero module boundary violations.

```text
node deploy/scripts/openapi-lint.mjs
openapi-lint: OK (73 paths, 81 operations, 3.1.0)
```

```text
redocly lint --config .redocly.yaml docs/openapi/openapi.yaml
Woohoo! Your API description is valid.
You have 3 warnings.
```

The 3 Redocly warnings are `operation-4xx-response` warnings for existing
health/liveness/readiness operations that declare no 4xx; they are not payroll
failures.

Payroll-only suite (feature + integration + unit):

```text
php artisan test tests/Feature/Payroll tests/Integration/Payroll tests/Unit/Modules/Payroll
Tests:    123 passed (30355 assertions)
```

Command smoke checks:

```text
php artisan payroll:seed-parameters --legal-entity=1 --year=2026 --approved-by=99 --env=testing
Seeded DEMO statutory parameters. These are synthetic values, not legal advice, and must not be used for real payroll.

php artisan payroll:reconciliation --env=testing
No calculated payroll runs matched.   (exit 0)
```

## Real gaps and concerns

- **No tax filing or bank clearing.** CSV exports are adapters with audit
  hashes only; they do not submit returns, clear funds, or certify statutory
  correctness.
- **DEMO statutory values only.** The seed command's brackets/rates are
  synthetic. Production requires legal-entity-specific versions with real
  approval evidence and legal review.
- **Payslip PDFs are not generated.** The API returns structured payslip
  header/line data.
- **Single SI/HF rule per legal entity.** Multi-locality component splits
  (pension/medical/unemployment/injury/maternity) remain a future parameter
  expansion.
- **Reopen approvals are recorded user ids, not separate Workflow cases.** The
  run audit and dual-approval evidence are stored, but each reopen does not
  create its own case.
- **Parallel-check format is fixed CSV.** `employment_id,gross_cents,net_cents`
  is documented; vendor exports need an adapter.
- **Time absence of evidence means a full month.** The reference build does
  not fail closed when Time has no attendance rows; production should decide
  whether missing Time evidence is a hard error.
- **No per-employee run membership writer is registered in production.**
  `PayrollRunMembershipDirectory` has a testing fake; a production
  implementation still needs to be provided by IAM/Payroll integration.
- **Receipt reconciliation is service-level.** There is no HTTP receipt
  endpoint in the brief; `ReceiptReconciliationService` is available for a
  later surface.
- **OpenAPI payroll descriptions are skeletal.** Paths, operations, tags,
  parameters, and response references exist and validate, but request/response
  schemas are generic envelopes rather than fully modeled payroll payloads.
- **Vendor directory is 154 MB and untracked.** It is a local copy required for
  correct Composer autoloading in this worktree and is not committed.
