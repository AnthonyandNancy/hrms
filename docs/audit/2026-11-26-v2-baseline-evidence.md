# FactoryHR V2.1 Baseline Evidence

- Generated: 2026-11-26T08:52:00.073Z

## git-commit

- command: `git rev-parse HEAD`
- exit code: 0

```text
2f8c464a51cd7d32da2d2c5da43326b51a5f4aca
```

## git-status

- command: `git status --short --branch`
- exit code: 0

```text
## feat/v2.1-remediation
 M .superpowers/sdd/progress.md
 M package.json
?? .superpowers/sdd/artifacts/plan-conflict-review.md
?? .superpowers/sdd/artifacts/plan-fix-verification.md
?? .superpowers/sdd/artifacts/policy_rewrite.py
?? .superpowers/sdd/artifacts/v1/
?? docs/audit/2026-11-26-v2-baseline-evidence.md
?? docs/audit/2026-11-26-v2-capability-inventory.md
?? scripts/collect_v2_baseline.mjs
?? scripts/tests/collect_v2_baseline.test.mjs
```

## node-version

- command: `node --version`
- exit code: 0

```text
v24.19.0
```

## pnpm-version

- command: `pnpm --version`
- exit code: 0

```text
10.34.5
```

## php-version

- command: `.tools/php/php.exe --version`
- exit code: 0

```text
PHP 8.4.28 (cli) (built: Nov 18 2026 00:57:45) (NTS Visual C++ 2022 x64)
Copyright (c) The PHP Group
Built by The PHP Group
Zend Engine v4.4.28, Copyright (c) Zend Technologies
```

## docker-info

- command: `docker info`
- exit code: 1

```text
Client:
 Version:    29.9.2
 Context:    desktop-linux
 Debug Mode: false
 Plugins:
  agent: Docker AI Agent Runner (Docker Inc.)
    Version:  v1.152.0
    Path:     <program-files-path>
  ai: Docker AI Agent - Ask Gordon (Docker Inc.)
    Version:  v1.33.0
    Path:     <program-files-path>
  buildx: Docker Buildx (Docker Inc.)
    Version:  v0.38.1
    Path:     <program-files-path>
  compose: Docker Compose (Docker Inc.)
    Version:  v5.6.1
    Path:     <program-files-path>
  debug: Get a shell into any image or container (Docker Inc.)
    Version:  0.49.0
    Path:     <program-files-path>
  desktop: Docker Desktop commands (Docker Inc.)
    Version:  4.97.0
    Path:     <program-files-path>
  dhi: CLI for managing Docker Hardened Images (Docker Inc.)
    Version:  v0.0.9
    Path:     <program-files-path>
  extension: Manages Docker extensions (Docker Inc.)
    Version:  v0.2.32
    Path:     <program-files-path>
  init: Creates Docker-related starter files for your project (Docker Inc.)
    Version:  v1.4.1
    Path:     <program-files-path>
  mcp: Docker MCP Plugin (Docker Inc.)
    Version:  v0.44.2
    Path:     <program-files-path>
  model: Docker Model Runner (Docker Inc.)
    Version:  v1.2.9
    Path:     <program-files-path>
  offload: Docker Offload (Docker Inc.)
    Version:  v0.7.21
    Path:     <program-files-path>
  pass: Docker Pass Secrets Manager Plugin (beta) (Docker Inc.)
    Version:  v0.3.2
    Path:     <program-files-path>
  sandbox: "docker sandbox" is deprecated, use Docker Sandboxes instead (Docker Inc.)
    Version:  v0.13.0
    Path:     <program-files-path>
  scout: Docker Scout (Docker Inc.)
    Version:  v1.27.0
    Path:     <program-files-path>

Server:
failed to connect to the docker API at <docker-pipe> check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
```

## backend-test

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`
- exit code: 1

```text
+    350 => '<repo>\backend\app\Modules\Workflow\Models\WorkflowTask.php',
+    351 => '<repo>\backend\app\Modules\Workflow\Models\WorkflowTemplate.php',
+    352 => '<repo>\backend\app\Modules\Workflow\Services\CaseCommandService.php',
+    353 => '<repo>\backend\app\Modules\Workflow\Services\CaseQueryService.php',
+    354 => '<repo>\backend\app\Modules\Workflow\Services\CaseService.php',
+    355 => '<repo>\backend\app\Modules\Workflow\Services\DecisionService.php',
+    356 => '<repo>\backend\app\Modules\Workflow\Services\DelegationService.php',
+    357 => '<repo>\backend\app\Modules\Workflow\Services\DomainEffectHandlerAdapter.php',
+    358 => '<repo>\backend\app\Modules\Workflow\Services\EffectExecutor.php',
+    359 => '<repo>\backend\app\Modules\Workflow\Services\EffectProjectionService.php',
+    360 => '<repo>\backend\app\Modules\Workflow\Services\EffectService.php',
+    361 => '<repo>\backend\app\Modules\Workflow\Services\PayrollApprovalEffectHandler.php',
+    362 => '<repo>\backend\app\Modules\Workflow\Services\PayrollApprovalWorkflowService.php',
+    363 => '<repo>\backend\app\Modules\Workflow\Services\ReassignmentService.php',
+    364 => '<repo>\backend\app\Modules\Workflow\Services\ReportingApprovalDirectoryService.php',
+    365 => '<repo>\backend\app\Modules\Workflow\Services\TemplateService.php',
+    366 => '<repo>\backend\app\Modules\Workflow\Services\WorkflowCaseGatewayService.php',
+    367 => '<repo>\backend\app\Modules\Workflow\Services\WorkflowDelegationRevocationService.php',
+    368 => '<repo>\backend\app\Shared\Authorization\AuthorizationSnapshot.php',
+    369 => '<repo>\backend\app\Shared\Authorization\EnsureCapability.php',
+    370 => '<repo>\backend\app\Shared\Authorization\ResolveLegalEntityContext.php',
+    371 => '<repo>\backend\app\Shared\Authorization\ResolveResourceLegalEntityContext.php',
+    372 => '<repo>\backend\routes\api.php',
+    373 => '<repo>\backend\../frontend/src\api\http.ts',
+    374 => '<repo>\backend\../frontend/src\api\normalise.ts',
+    375 => '<repo>\backend\../frontend/src\generated\api.ts',
+    376 => '<repo>\backend\../frontend/src\mocks\adapter.ts',
+    377 => '<repo>\backend\../frontend/src\modules\people\components\DepartmentForms.vue',
+    378 => '<repo>\backend\../frontend/src\modules\people\components\EmployeeCreateForm.vue',
+    379 => '<repo>\backend\../frontend/src\modules\people\components\MovementForms.vue',
+    380 => '<repo>\backend\../frontend/src\modules\people\components\PositionForms.vue',
+    381 => '<repo>\backend\../frontend/src\modules\people\peopleRead.ts',
+    382 => '<repo>\backend\../frontend/src\modules\platform\components\PlatformWorkspace.vue',
+    383 => '<repo>\backend\../frontend/src\modules\talent\TalentForm.vue',
+    384 => '<repo>\backend\../frontend/src\views\AdminView.vue',
+    385 => '<repo>\backend\../frontend/src\views\people\DepartmentForms.vue',
+    386 => '<repo>\backend\../frontend/src\views\people\EmployeeCreateForm.vue',
+    387 => '<repo>\backend\../frontend/src\views\people\MovementForms.vue',
+    388 => '<repo>\backend\../frontend/src\views\people\peopleRead.ts',
+    389 => '<repo>\backend\../frontend/src\views\people\PositionForms.vue',
+    390 => '<repo>\backend\../frontend/src\views\talent\TalentForm.vue',
+    391 => '<repo>\backend\../frontend/src\views\timepay\definitions.ts',
+    392 => '<repo>\backend\../frontend/src\views\timepay\panels\AttendancePanel.vue',
+    393 => '<repo>\backend\../frontend/src\views\timepay\panels\TravelPanel.vue',
+    394 => '<repo>\backend\../frontend/src\views\timepay\useTimePayrollLoad.ts',
+]

<worktree:v2.1-remediation>\backend\tests\Contract\NoTenantNoLegalEntityTest.php:37

FAILURES!
Tests: 1123, Assertions: 51283, Failures: 1.

OpenApiSchemaContractTest: 0 success-schema violations across 323 operations

OpenApiSchemaContractTest: 0 error-set violations across 323 operations

OpenApiSchemaContractTest: 0 capability violations across 323 operations
```

## backend-pint

- command: `cd backend && ../.tools/php/php.exe vendor/bin/pint --test`
- exit code: 0

```text
....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ....................................................................................................................
  ........

  ──────────────────────────────────────────────────────────────────────────────────────────────────────────── Laravel  
    PASS   ................................................................................................ 1168 files
```

## backend-phpstan

- command: `cd backend && ../.tools/php/php.exe vendor/bin/phpstan analyse --no-progress`
- exit code: 0

```text
[OK] No errors
```

## capability-audit

- command: `cd backend && ../.tools/php/php.exe artisan capability:audit`
- exit code: 0

```text
/time/attendance/closings/{id}/reopen time.attendance.reopen
GUARDED POST v1/time/attendance/corrections time.attendance.correct.request
GUARDED GET v1/time/attendance/devices time.attendance.device.read
GUARDED POST v1/time/attendance/recalculate time.attendance.manage
GUARDED GET v1/time/attendance/rules time.attendance.rule.read
GUARDED POST v1/time/attendance/rules time.attendance.rule.manage
GUARDED GET v1/time/attendance/{employment_id} time.attendance.read
GUARDED GET v1/time/calendars/{legal_entity_id} time.calendar.read
GUARDED GET v1/time/leave/balances time.leave.read
GUARDED GET v1/time/leave/ledger time.leave.ledger.read
GUARDED GET v1/time/leave/policies time.leave.read
GUARDED POST v1/time/leave/preview time.leave.read
GUARDED GET v1/time/leave/requests time.leave.read
GUARDED POST v1/time/leave/requests time.leave.request
GUARDED POST v1/time/leave/requests/{caseId}/cancel time.leave.request.cancel
GUARDED GET v1/time/leave/types time.leave.read
GUARDED GET v1/time/overtime/policies time.overtime.read
GUARDED GET v1/time/overtime/records time.overtime.read
GUARDED GET v1/time/overtime/requests time.overtime.read
GUARDED POST v1/time/overtime/requests time.overtime.request
GUARDED POST v1/time/overtime/requests/{id}/confirm time.overtime.confirm
GUARDED GET v1/time/overtime/timeoff-balances time.timeoff.read
GUARDED POST v1/time/punches/import time.punch.import
GUARDED GET v1/time/shift-schedules time.shift.schedule.read
GUARDED POST v1/time/shift-schedules time.shift.schedule.manage
GUARDED GET v1/time/shifts time.shift.read
GUARDED GET v1/training/checkins training.checkin.manage
GUARDED GET v1/training/courses training.course.manage
GUARDED POST v1/training/courses training.course.manage
GUARDED GET v1/training/enrollments training.enrollment.manage
GUARDED POST v1/training/enrollments training.enrollment.manage
GUARDED POST v1/training/enrollments/{id}/complete training.completion.manage
GUARDED GET v1/training/exams training.exam.manage
GUARDED POST v1/training/exams training.exam.manage
GUARDED POST v1/training/exams/{id}/publish training.exam.manage
GUARDED GET v1/training/feedback training.feedback.manage
GUARDED POST v1/training/feedback training.feedback.manage
GUARDED GET v1/training/plans training.plan.manage
GUARDED POST v1/training/plans training.plan.manage
GUARDED GET v1/training/programs training.program.read
GUARDED POST v1/training/programs training.program.manage
GUARDED GET v1/training/records training.record.read
GUARDED GET v1/training/records/export training.record.read
GUARDED GET v1/training/report training.report.read
GUARDED GET v1/training/reports training.report.read
GUARDED GET v1/training/sessions training.session.manage
GUARDED POST v1/training/sessions training.session.manage
GUARDED GET v1/training/sessions/{id}/checkins training.checkin.manage
GUARDED POST v1/training/sessions/{id}/checkins training.checkin.manage
GUARDED GET v1/travel/expenses time.travel.expense
GUARDED POST v1/travel/expenses time.travel.expense.manage
GUARDED GET v1/travel/policies time.travel.policy.read
GUARDED POST v1/travel/policies time.travel.policy.manage
GUARDED GET v1/travel/requests time.travel.read
GUARDED POST v1/travel/requests time.travel.request
GUARDED POST v1/travel/requests/{id}/approve time.travel.approve
GUARDED POST v1/travel/requests/{id}/cancel time.travel.request
GUARDED GET v1/users iam.user.read
GUARDED POST v1/users iam.user.manage
GUARDED PATCH v1/users/{id} iam.user.manage
GUARDED GET v1/users/{id}/bindings iam.binding.manage
GUARDED POST v1/users/{id}/bindings iam.binding.manage
GUARDED GET v1/users/{id}/permissions-preview iam.user.read
GUARDED POST v1/users/{id}/roles iam.role.manage
GUARDED DELETE v1/users/{id}/roles/{roleCode} iam.role.manage
GUARDED GET v1/workflow/templates workflow.case.read
GUARDED POST v1/workflow/templates workflow.case.admin
Public routes (explicit allowlist): 6
Capability audit OK.
```

## arch-test

- command: `cd backend && ../.tools/php/php.exe artisan arch:test`
- exit code: 0

```text
Scanned 685 module PHP files.
WARN Platform/Migration/Adapters/DatabaseMigrationTarget.php:114 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
WARN Platform/Retention/RetentionService.php:65 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
WARN Platform/Retention/RetentionService.php:123 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
WARN Platform/Retention/RetentionService.php:138 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
Warnings (non-blocking): 4
Module boundaries OK.
```

## frontend-typecheck

- command: `pnpm typecheck`
- exit code: 0

```text
> factoryhr@1.0.0 typecheck <worktree:v2.1-remediation>
> node scripts/frontend-workspace.mjs typecheck


> @hrms/frontend@0.1.0 typecheck <worktree:v2.1-remediation>\frontend
> vue-tsc -b
```

## frontend-lint

- command: `pnpm lint`
- exit code: 0

```text
> factoryhr@1.0.0 lint <worktree:v2.1-remediation>
> node scripts/frontend-workspace.mjs lint


> @hrms/frontend@0.1.0 lint <worktree:v2.1-remediation>\frontend
> eslint . --max-warnings=0
```

## frontend-unit

- command: `pnpm test:unit`
- exit code: 1

```text
tive custom element, make sure to exclude it from component resolution via compilerOptions.isCustomElement. 
  at <SystemUsersPage ref="VTU_COMPONENT" > 
  at <VTUROOT>

stderr | tests/unit/systemUsersPage.spec.ts > system users permissions management > revokes a role and shows immediate denial reasons in the preview
[Vue warn]: Failed to resolve component: el-button
If this is a native custom element, make sure to exclude it from component resolution via compilerOptions.isCustomElement. 
  at <SystemUsersPage ref="VTU_COMPONENT" > 
  at <VTUROOT>

stderr | tests/unit/systemUsersPage.spec.ts > system users permissions management > revokes a role and shows immediate denial reasons in the preview
[Vue warn]: Failed to resolve component: el-button
If this is a native custom element, make sure to exclude it from component resolution via compilerOptions.isCustomElement. 
  at <SystemUsersPage ref="VTU_COMPONENT" > 
  at <VTUROOT>

stderr | tests/unit/systemUsersPage.spec.ts > system users permissions management > revokes a role and shows immediate denial reasons in the preview
[Vue warn]: Failed to resolve component: el-button
If this is a native custom element, make sure to exclude it from component resolution via compilerOptions.isCustomElement. 
  at <SystemUsersPage ref="VTU_COMPONENT" > 
  at <VTUROOT>

stderr | tests/unit/systemUsersPage.spec.ts > system users permissions management > revokes a role and shows immediate denial reasons in the preview
[Vue warn]: Failed to resolve component: el-button
If this is a native custom element, make sure to exclude it from component resolution via compilerOptions.isCustomElement. 
  at <SystemUsersPage ref="VTU_COMPONENT" > 
  at <VTUROOT>

stderr | tests/unit/systemUsersPage.spec.ts > system users permissions management > revokes a role and shows immediate denial reasons in the preview
[Vue warn]: Failed to resolve component: el-button
If this is a native custom element, make sure to exclude it from component resolution via compilerOptions.isCustomElement. 
  at <SystemUsersPage ref="VTU_COMPONENT" > 
  at <VTUROOT>

stderr | tests/unit/systemUsersPage.spec.ts > system users permissions management > revokes a role and shows immediate denial reasons in the preview
[Vue warn]: Failed to resolve component: el-button
If this is a native custom element, make sure to exclude it from component resolution via compilerOptions.isCustomElement. 
  at <SystemUsersPage ref="VTU_COMPONENT" > 
  at <VTUROOT>

stderr | tests/unit/dictionary.spec.ts > dictionary > returns a safe placeholder and records unknown codes
[dictionary] unknown code people.employeeStatus=UNKNOWN_CODE


⎯⎯⎯⎯⎯⎯ Failed Suites 1 ⎯⎯⎯⎯⎯⎯⎯

 FAIL  tests/unit/pageComponentUniqueness.spec.ts [ tests/unit/pageComponentUniqueness.spec.ts ]
SyntaxError: Invalid or unexpected token
 ❯ tests/unit/pageComponentUniqueness.spec.ts:7:1
      5| import { fileURLToPath } from 'node:url'
      6| import { afterEach, describe, expect, it } from 'vitest'
      7| import {
       | ^
      8|   DEFAULT_SHARED_ALLOWLIST,
      9|   auditPageComponents,

⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯[1/2]⎯


⎯⎯⎯⎯⎯⎯⎯ Failed Tests 1 ⎯⎯⎯⎯⎯⎯⎯

 FAIL  tests/unit/openapiGeneration.spec.ts > OpenAPI TypeScript generation > committed types are current and contain real paths and component schemas
AssertionError: 
[openapi:generate] generated types are stale. Run pnpm openapi:generate.
[openapi:generate] committed: <worktree:v2.1-remediation>\frontend\src\generated\api.ts
[openapi:generate] freshly generated: <temp>\factoryhr-openapi-check-UiyH1p\api.ts
: expected 1 to be +0 // Object.is equality

- Expected
+ Received

- 0
+ 1

 ❯ tests/unit/openapiGeneration.spec.ts:43:65
     41|     const result = run(['--check'])
     42| 
     43|     expect(result.status, `${result.stdout}\n${result.stderr}`).toBe(0)
       |                                                                 ^
     44| 
     45|     const generated = readFileSync(committed, 'utf8')

⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯⎯[2/2]⎯
```

## page-components

- command: `node scripts/audit_page_components.mjs`
- exit code: 0

```text
{
  "ok": true,
  "total": 160,
  "expected": 160,
  "mapped": 154,
  "moduleMapped": 154,
  "baseMapped": 6,
  "mappedIncludingBase": 160,
  "missingMappings": [],
  "missingFiles": [],
  "staleManifestKeys": [],
  "allowedSharedGroups": [
    {
      "keys": [
        "base/AccountMfaView",
        "base/AccountSessionsView"
      ],
      "file": "frontend/src/views/AccountSecurityView.vue",
      "reason": "MFA enrollment and session management are two sections of the single AccountSecurityView account-security screen."
    }
  ],
  "failures": []
}
```

## frontend-modules

- command: `node scripts/audit_frontend_modules.mjs`
- exit code: 0

```text
{
  "ok": true,
  "scanned": 186,
  "pagesScanned": 158,
  "moduleComponentsScanned": 12,
  "moduleApiScanned": 0,
  "moduleOtherScanned": 16,
  "sharedComponentsScanned": 22,
  "allowlistEntries": 1,
  "failures": []
}
```

## actions-check

- command: `node scripts/audit_action_manifest.mjs`
- exit code: 0

```text
actions:check OK (160 pages, 3 write pages, 2 pages with actions, 3 actions, 1 public write exceptions)
```

## openapi-lint

- command: `node deploy/scripts/openapi-lint.mjs`
- exit code: 0

```text
openapi-lint: OK (251 paths, 323 operations, 3.1.0)
```

## openapi-validate

- command: `pnpm openapi:validate`
- exit code: 0

```text
> factoryhr@1.0.0 openapi:validate <worktree:v2.1-remediation>
> redocly lint --config .redocly.yaml docs/openapi/openapi.yaml

[1] docs\openapi\openapi.yaml:11711:7 at #/paths/~1v1~1health~1live/get/responses

Operation must have at least one `4XX` response.

11709 |   - System
11710 | security: []
11711 | responses:
      | ^^^^^^^^^
11712 |   "200":
11713 |     description: Process is alive; no dependency is checked.

Warning was generated by the operation-4xx-response rule.

Reference: https://redocly.com/docs/cli/rules/oas/operation-4xx-response


[2] docs\openapi\openapi.yaml:11727:7 at #/paths/~1v1~1health~1ready/get/responses

Operation must have at least one `4XX` response.

11725 |   - System
11726 | security: []
11727 | responses:
      | ^^^^^^^^^
11728 |   "200":
11729 |     description: Database, cache and storage are ready.

Warning was generated by the operation-4xx-response rule.

Reference: https://redocly.com/docs/cli/rules/oas/operation-4xx-response
```


## 人工核验

- Git commit: `2f8c464a51cd7d32da2d2c5da43326b51a5f4aca`
- PHP: `.tools/php/php.exe` — PHP 8.4.28 (cli) (built: Nov 18 2026 00:57:45) (NTS Visual C++ 2022 x64)
Copyright (c) The PHP Group
Built by The PHP Group
Zend Engine v4.4.28, Copyright (c) Zend Technologies
- Node/pnpm: Node v24.19.0, pnpm 10.34.5
- MySQL/Docker: NOT RUN. `docker info` exited 1; daemon unavailable: failed to connect to the docker API at <docker-pipe> check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
- 当前失败门禁清单:
- `docker-info`: exit code 1; command `docker info`.
- `backend-test`: exit code 1; command `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`.
- `frontend-unit`: exit code 1; command `pnpm test:unit`.
- 现有未提交文件（采集时）:

```text
## feat/v2.1-remediation
 M .superpowers/sdd/progress.md
 M package.json
?? .superpowers/sdd/artifacts/plan-conflict-review.md
?? .superpowers/sdd/artifacts/plan-fix-verification.md
?? .superpowers/sdd/artifacts/policy_rewrite.py
?? .superpowers/sdd/artifacts/v1/
?? docs/audit/2026-11-26-v2-baseline-evidence.md
?? docs/audit/2026-11-26-v2-capability-inventory.md
?? scripts/collect_v2_baseline.mjs
?? scripts/tests/collect_v2_baseline.test.mjs
```
