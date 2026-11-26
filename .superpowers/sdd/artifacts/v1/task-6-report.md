# Task 6 Report — Backend Catalog, Page Actions and OpenAPI Labels

- **Status:** COMPLETE
- **Date:** 2026-11-26
- **Worktree:** `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- **Branch:** `feat/v2.1-remediation`
- **Scope:** Backend + OpenAPI half only. Task 5 ran first per controller ordering and its files were not touched.

## Delivered

| File | Change |
|---|---|
| `docs/openapi/openapi.yaml` | Added `x-action-label` to all 173 capability-guarded POST/PUT/PATCH/DELETE operations. No path, method, request body or capability changed. Public login/MFA-challenge writes remain unlabelled and unguarded. |
| `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php` | Aligned page `actions` and `required_apis` with the real OpenAPI write capabilities; removed stale/nonexistent API paths; removed action codes that no page/capability relationship backs; replaced legacy reporting/notification/contract action codes only where a real replacement exists. |
| `backend/tests/Contract/PagePermissionCoverageTest.php` | Added 3 contract tests: actions exist in `CapabilityCatalog`; required APIs exist in OpenAPI; every page write API's `x-capability` is declared in that page's `actions`. |
| `backend/tests/Contract/OpenApiActionLabelTest.php` | New: every capability write has a non-empty, Chinese, unique label; every page write API's capability appears in `actions`; public writes are not labelled. |
| `docs/audit/2026-11-26-v2-action-gaps.json` | Added a `capabilityGaps` section with 15 individually-owned capability gaps. Existing `gaps` write-operation list untouched. |

`CapabilityCatalog.php` was already complete: all page `actions` resolved to existing capability codes, so no new codes were invented. No changes were needed there.

## Tests / Gates (actual output)

```text
cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan test --compact --filter='PagePermissionCoverageTest|OpenApiActionLabelTest'
Tests: 15 passed (3355 assertions)
EXIT=0

cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan capability:audit
Capability audit OK.
EXIT=0

node deploy/scripts/openapi-lint.mjs
openapi-lint: OK (251 paths, 323 operations, 3.1.0)
EXIT=0

pnpm openapi:validate
docs/openapi/openapi.yaml: validated in ...ms
Woohoo! Your API description is valid. 🎉
You have 2 warnings.
EXIT=0
```

The 2 Redocly warnings are existing `operation-4xx-response` warnings for `/v1/health/live` and `/v1/health/ready`. They are warnings only; validation exited 0.

Additional evidence:

```text
backend artisan test --filter='ActionManifestParityTest|PagePermissionCoverageTest|OpenApiActionLabelTest'
Tests: 16 passed (3372 assertions)

backend artisan test --filter='OpenApiContractTest|OpenApiSchemaContractTest|OpenApiLintCliTest|...'
Tests: 30 passed (9783 assertions)

node scripts/generate_action_manifest.mjs
action manifest OK (2 pages, 3 actions, 170 explicit gaps)

node scripts/audit_action_manifest.mjs
actions:check OK (160 pages, 3 write pages, 2 pages with actions, 3 actions, 170 explicit gaps, 1 public write exceptions)

guard measure: 173 capability-guarded writes, 173 labelled, 0 missing, 0 non-Chinese
```

Full `php artisan test` was attempted with `-d memory_limit=1G` but the suite segfaulted/OOM'd in `TermsVersionTest` at the configured 128 MB worker limit. Targeted suites above are green. `NoTenantNoLegalEntityTest` remains the expected pre-cutover Volume 1 red gate (1927 legacy identifier hits), owned by Volume 2 Tasks 2/3; no broad scan changes were made here.

## TDD evidence

- Wrote `PagePermissionCoverageTest` additions first; RED showed 15 missing OpenAPI page operations, 30 action capabilities with no OpenAPI relationship, and 3 page write-capability mismatches.
- Wrote `OpenApiActionLabelTest` first; RED listed all 173 capability writes missing labels.
- Implemented OpenAPI labels and catalog alignment; both suites then green.

## Gaps recorded (15 capabilities)

All are in `docs/audit/2026-11-26-v2-action-gaps.json` under `capabilityGaps` with owner + missing operation:

| Capability | Owner |
|---|---|
| `people.employee.export` | Volume 3 Task 4 |
| `people.contract.export` | Volume 3 Task 8 |
| `org.unit.export` | Volume 3 Task 5 |
| `org.position.export` | Volume 3 Task 6 |
| `recruitment.requisition.export` | Volume 3 Task 14 |
| `payroll.payslip.read` | Volume 3 Task 12 |
| `time.leave.admin` | Volume 3 Task 10 |
| `time.overtime.manage` | Volume 3 Task 11 |
| `time.travel.manage` | Volume 3 Task 11 |
| `workflow.template.manage` | Volume 3 Task 17 |
| `notification.manage` | Volume 3 Task 16 |
| `iam.permission.read` | Volume 3 Task 17 |
| `system.settings.manage` | Volume 3 Task 17 |
| `notification.read` | Volume 3 Task 16 |
| `reporting.report.create` | Volume 3 Task 16 |

`ActionManifestParityTest.php` was deliberately NOT created/modified by this task. Task 5 had already created it; it passes against this worktree (`1 test, 17 assertions`). Task 5/6-completion owns the final `docs/generated/page-actions.json` parity gate.

## Notes

- `hr-employees` / `employee-detail` now declare the real archive/restore capabilities and APIs; employee export is a recorded Volume 3 gap.
- `system-users` and `system-roles` actions now include the IAM user/role/scope/field capabilities their real write APIs require.
- `hr-contracts-renewals` / `hr-contracts-changes` now include `lifecycle.contract.manage`, matching `POST /v1/lifecycle/contract-renewals`.
- Stale OpenAPI path placeholders were corrected to actual operation keys (`{employmentId}` for employee profile/contract reads; `/v1/time/overtime/...`).
- Task 5 files (`docs/generated/**`, `scripts/generate_action_manifest.mjs`, `scripts/audit_action_manifest.mjs`, `frontend/src/router/registry.ts`, `frontend/src/generated/**`, `ActionManifestParityTest.php`) were not staged or edited.
- No frontend source/tests were modified.
