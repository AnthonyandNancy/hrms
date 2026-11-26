# 第 1 册验收记录（V2.1 Volume 1）

- Snapshot commit: `90e8602b201611b427ed4406edae2875b8780261`
- Report mode: recorder. A non-zero product gate does not make this generator fail.
- Evidence tails contain the last 1500 characters of captured stdout+stderr (per gate).

## Product gates

| gate | exit code | result | evidence tail |
| --- | --- | --- | --- |
| `backend-phpunit` | 1 | FAIL | 'backend/../docs/openapi/openapi.yaml:16794', +    1911 => 'backend/../docs/openapi/openapi.yaml:16801', +    1912 => 'backend/../docs/openapi/openapi.yaml:17519', +    1913 => 'backend/../docs/openapi/openapi.yaml:17523', +    1914 => 'backend/../docs/openapi/openapi.yaml:17604', +    1915 => 'backend/../docs/openapi/openapi.yaml:17609', +    1916 => 'backend/../docs/openapi/openapi.yaml:17782', +    1917 => 'backend/../docs/openapi/openapi.yaml:17867', +    1918 => 'backend/../docs/openapi/openapi.yaml:17872', +    1919 => 'backend/../docs/openapi/openapi.yaml:17917', +    1920 => 'backend/../docs/openapi/openapi.yaml:18118', +    1921 => 'backend/../docs/openapi/openapi.yaml:18126', +    1922 => 'backend/../docs/openapi/openapi.yaml:18152', +    1923 => 'backend/../docs/openapi/openapi.yaml:19165', +    1924 => 'backend/../docs/openapi/openapi.yaml:19328', +    1925 => 'backend/../docs/openapi/openapi.yaml:20252', +    1926 => 'backend/../docs/openapi/openapi.yaml:20331', +    1927 => 'backend/../docs/openapi/openapi.yaml:20336', +    1928 => 'backend/../docs/openapi/openapi.yaml:20808', +]  D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\tests\Contract\NoTenantNoLegalEntityTest.php:92  FAILURES! Tests: 1136, Assertions: 52346, Failures: 1.  OpenApiSchemaContractTest: 0 success-schema violations across 323 operations  OpenApiSchemaContractTest: 0 error-set violations across 323 operations  OpenApiSchemaContractTest: 0 capability violations across 323 operations |
| `backend-pint` | 0 | PASS | ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ....................................................................................................................   ............    ──────────────────────────────────────────────────────────────────────────────────────────────────────────── Laravel       PASS   ................................................................................................ 1172 files |
| `backend-phpstan` | 0 | PASS | [OK] No errors                                                                                                          Note: Using configuration file D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\phpstan.neon. |
| `capability-audit` | 0 | PASS | ining/records training.record.read GUARDED GET v1/training/records/export training.record.read GUARDED GET v1/training/report training.report.read GUARDED GET v1/training/reports training.report.read GUARDED GET v1/training/sessions training.session.manage GUARDED POST v1/training/sessions training.session.manage GUARDED GET v1/training/sessions/{id}/checkins training.checkin.manage GUARDED POST v1/training/sessions/{id}/checkins training.checkin.manage GUARDED GET v1/travel/expenses time.travel.expense GUARDED POST v1/travel/expenses time.travel.expense.manage GUARDED GET v1/travel/policies time.travel.policy.read GUARDED POST v1/travel/policies time.travel.policy.manage GUARDED GET v1/travel/requests time.travel.read GUARDED POST v1/travel/requests time.travel.request GUARDED POST v1/travel/requests/{id}/approve time.travel.approve GUARDED POST v1/travel/requests/{id}/cancel time.travel.request GUARDED GET v1/users iam.user.read GUARDED POST v1/users iam.user.manage GUARDED PATCH v1/users/{id} iam.user.manage GUARDED GET v1/users/{id}/bindings iam.binding.manage GUARDED POST v1/users/{id}/bindings iam.binding.manage GUARDED GET v1/users/{id}/permissions-preview iam.user.read GUARDED POST v1/users/{id}/roles iam.role.manage GUARDED DELETE v1/users/{id}/roles/{roleCode} iam.role.manage GUARDED GET v1/workflow/templates workflow.case.read GUARDED POST v1/workflow/templates workflow.case.admin Public routes (explicit allowlist): 6 Capability audit OK. |
| `arch-test` | 0 | PASS | Scanned 685 module PHP files. WARN Platform/Migration/Adapters/DatabaseMigrationTarget.php:114 [Platform] Cannot resolve dynamic table name to an owning module; review manually. WARN Platform/Retention/RetentionService.php:65 [Platform] Cannot resolve dynamic table name to an owning module; review manually. WARN Platform/Retention/RetentionService.php:123 [Platform] Cannot resolve dynamic table name to an owning module; review manually. WARN Platform/Retention/RetentionService.php:138 [Platform] Cannot resolve dynamic table name to an owning module; review manually. Warnings (non-blocking): 4 Module boundaries OK. |
| `backend-contract` | 1 | FAIL | 82', +    1917 => 'backend/../docs/openapi/openapi.yaml:17867', +    1918 => 'backend/../docs/openapi/openapi.yaml:17872', +    1919 => 'backend/../docs/openapi/openapi.yaml:17917', +    1920 => 'backend/../docs/openapi/openapi.yaml:18118', +    1921 => 'backend/../docs/openapi/openapi.yaml:18126', +    1922 => 'backend/../docs/openapi/openapi.yaml:18152', +    1923 => 'backend/../docs/openapi/openapi.yaml:19165', +    1924 => 'backend/../docs/openapi/openapi.yaml:19328', +    1925 => 'backend/../docs/openapi/openapi.yaml:20252', +    1926 => 'backend/../docs/openapi/openapi.yaml:20331', +    1927 => 'backend/../docs/openapi/openapi.yaml:20336', +    1928 => 'backend/../docs/openapi/openapi.yaml:20808', +][39;22m    at [32mtests\Contract\NoTenantNoLegalEntityTest.php[39m:[32m92[39m      88▕                 ...$this->scanText($relativePath, (string) file_get_contents($explicitFile)),      89▕             );      90▕         }      91▕    ➜  92▕         $this->assertSame([], $violations, sprintf(      93▕             "Tenant/legal-entity identifiers remain (%d):\n%s",      94▕             count($violations),      95▕             implode("\n", array_slice($violations, 0, 80)),      96▕         ));    [33m1   [39m[39;1mtests\Contract\NoTenantNoLegalEntityTest.php[39;22m:[39;1m92[39;22m     [90mTests:[39m    [31;1m1 failed[39;22m[90m,[39m[39m [39m[32;1m16 passed[39;22m[90m (3367 assertions)[39m   [90mDuration:[39m [39m3.27s[39m  exit 1 |
| `frontend-typecheck` | 0 | PASS | > factoryhr@1.0.0 typecheck D:\code\ai\factoryHR\.worktrees\v2.1-remediation > node scripts/frontend-workspace.mjs typecheck   > @hrms/frontend@0.1.0 typecheck D:\code\ai\factoryHR\.worktrees\v2.1-remediation\frontend > vue-tsc -b |
| `frontend-lint` | 0 | PASS | > factoryhr@1.0.0 lint D:\code\ai\factoryHR\.worktrees\v2.1-remediation > node scripts/frontend-workspace.mjs lint   > @hrms/frontend@0.1.0 lint D:\code\ai\factoryHR\.worktrees\v2.1-remediation\frontend > eslint . --max-warnings=0 |
| `frontend-unit` | 0 | PASS | "/notices"  stderr \| tests/unit/shellMenu.spec.ts > V1.1 expandable shell menu > ignores malformed or non-string persisted expansion values [Vue Router warn]: No match found for location with path "/payroll/components" [Vue Router warn]: No match found for location with path "/payroll/plans" [Vue Router warn]: No match found for location with path "/payroll/employee-plans" [Vue Router warn]: No match found for location with path "/payroll/statutory" [Vue Router warn]: No match found for location with path "/payroll/inputs" [Vue Router warn]: No match found for location with path "/payroll/batches" [Vue Router warn]: No match found for location with path "/payroll/reviews" [Vue Router warn]: No match found for location with path "/payroll/disbursement" [Vue Router warn]: No match found for location with path "/payroll/payslips" [Vue Router warn]: No match found for location with path "/payroll/adjustments" [Vue Router warn]: No match found for location with path "/payroll/reports" [Vue Router warn]: No match found for location with path "/notices"  stderr \| tests/unit/talentPages.spec.ts > Wave 4 talent domain components > loads candidate evaluations for a scoped candidate without candidate PII leakage [Vue Router warn]: No match found for location with path "/recruitment/candidates/evaluations?candidate_id=31"  stderr \| tests/unit/dictionary.spec.ts > dictionary > returns a safe placeholder and records unknown codes [dictionary] unknown code people.employeeStatus=UNKNOWN_CODE |
| `audit_page_components` | 0 | PASS | {   "ok": true,   "total": 160,   "expected": 160,   "mapped": 154,   "moduleMapped": 154,   "baseMapped": 6,   "mappedIncludingBase": 160,   "missingMappings": [],   "missingFiles": [],   "staleManifestKeys": [],   "allowedSharedGroups": [     {       "keys": [         "base/AccountMfaView",         "base/AccountSessionsView"       ],       "file": "frontend/src/views/AccountSecurityView.vue",       "reason": "MFA enrollment and session management are two sections of the single AccountSecurityView account-security screen."     }   ],   "failures": [] } |
| `audit_frontend_modules` | 0 | PASS | {   "ok": true,   "scanned": 186,   "pagesScanned": 158,   "moduleComponentsScanned": 12,   "moduleApiScanned": 0,   "moduleOtherScanned": 16,   "sharedComponentsScanned": 22,   "allowlistEntries": 1,   "failures": [] } |
| `audit_action_manifest` | 0 | PASS | actions:check OK (160 pages, 3 write pages, 2 pages with actions, 3 actions, 170 explicit gaps, 1 public write exceptions) |
| `audit_page_model` | 1 | FAIL | {   "ok": false,   "failures": [     "hr-employees-create: action-like path /hr/employees/create",     "hr-departments-editor: action-like path /hr/departments/editor",     "hr-positions-editor: action-like path /hr/positions/editor",     "hr-contracts-editor: action-like path /hr/contracts/editor",     "recruitment-requests-create: action-like path /recruitment/requests/create",     "notices-editor: action-like path /notices/editor",     "notices-archive: action-like path /notices/archive",     "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 员工档案",     "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 部门管理",     "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 岗位管理",     "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 员工异动",     "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 劳动合同"   ] } exit 1 |
| `audit_ui_primitives` | 1 | FAIL | {   "ok": false,   "failures": [     "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw input (line 277)",     "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw select (line 302)",     "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw details (line 274)",     "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw table (line 149)",     "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw input (line 173)",     "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw select (line 193)",     "frontend/src/modules/talent/TalentForm.vue: raw table (line 1163)",     "frontend/src/modules/talent/TalentForm.vue: raw input (line 1013)",     "frontend/src/modules/talent/TalentForm.vue: raw select (line 1069)",     "frontend/src/modules/talent/components/TalentWorkspacePage.vue: raw table (line 262)",     "frontend/src/modules/talent/components/TalentWorkspacePage.vue: raw input (line 243)"   ] } exit 1 |
| `audit_user_copy` | 1 | FAIL | {   "ok": false,   "failures": [     "frontend/src/modules/people/components/ContractForm.vue: English enum 'ACTIVE' (line 29)",     "frontend/src/modules/people/components/EmployeeCreateForm.vue: English enum 'FULL_TIME' (line 16)",     "frontend/src/modules/people/components/MovementForms.vue: API path 'POST /v1/' (line 240)",     "frontend/src/modules/people/components/MovementForms.vue: English enum 'FULL_TIME' (line 49)",     "frontend/src/modules/people/components/PeopleWorkspace.vue: development marker 'Wave' (line 144)",     "frontend/src/modules/people/components/PositionForms.vue: API path 'POST /v1/' (line 77)",     "frontend/src/modules/people/components/PositionForms.vue: English enum 'ACTIVE' (line 40)",     "frontend/src/modules/platform/components/PlatformWorkspace.vue: API path 'GET /v1/' (line 2230)",     "frontend/src/modules/platform/components/PlatformWorkspace.vue: English enum 'ACTIVE' (line 152)",     "frontend/src/modules/platform/components/PlatformWorkspace.vue: field profile code 'P1_INTERNAL' (line 170)",     "frontend/src/modules/platform/pages/SystemRolesPage.vue: field profile code 'P0_PUBLIC' (line 15)",     "frontend/src/modules/platform/pages/SystemUsersPage.vue: API path 'GET /v1/' (line 137)"   ] } exit 1 |
| `openapi-lint` | 0 | PASS | openapi-lint: OK (251 paths, 323 operations, 3.1.0) |
| `openapi:validate` | 0 | PASS | > factoryhr@1.0.0 openapi:validate D:\code\ai\factoryHR\.worktrees\v2.1-remediation > redocly lint --config .redocly.yaml docs/openapi/openapi.yaml  [1] docs\openapi\openapi.yaml:11772:7 at #/paths/~1v1~1health~1live/get/responses  Operation must have at least one `4XX` response.  11770 \|   - System 11771 \| security: [] 11772 \| responses:       \| ^^^^^^^^^ 11773 \|   "200": 11774 \|     description: Process is alive; no dependency is checked.  Warning was generated by the operation-4xx-response rule.  Reference: https://redocly.com/docs/cli/rules/oas/operation-4xx-response   [2] docs\openapi\openapi.yaml:11788:7 at #/paths/~1v1~1health~1ready/get/responses  Operation must have at least one `4XX` response.  11786 \|   - System 11787 \| security: [] 11788 \| responses:       \| ^^^^^^^^^ 11789 \|   "200": 11790 \|     description: Database, cache and storage are ready.  Warning was generated by the operation-4xx-response rule.  Reference: https://redocly.com/docs/cli/rules/oas/operation-4xx-response   validating docs\openapi\openapi.yaml... docs\openapi\openapi.yaml: validated in 203ms  Woohoo! Your API description is valid. 🎉 You have 2 warnings. |
| `node-tests` | 0 | PASS | alidate reports a capability-guarded OpenAPI write that has no action (0.1247ms) ✔ validate honours only explicitly listed gaps (0.2272ms) ✔ rejects action capability that differs from OpenAPI (0.1393ms) ✔ every registry page has one disposition and a target (1.1098ms) ✔ missing decision fails closed (0.3797ms) ✔ missing target, owner or evidence fails closed (0.1799ms) ✔ invalid disposition fails closed (0.1205ms) ✔ deprecate requires evidence quoting written user approval (0.6302ms) ✔ registry parser handles compact and multiline page objects (0.9903ms) ✔ all-green product gates produce a strict YES even when docker is unavailable (0.9913ms) ✔ one red product gate produces NO — blocked with a bullet for each red gate (0.2144ms) ✔ only plan-approved carried gates produce YES with named owners (0.183ms) ✔ an environment failure is NOT RUN, listed separately, and is not a product red (0.1439ms) ✔ the report is deterministic for the same entries and snapshot commit (0.1883ms) ✔ runAcceptance executes selected gates sequentially and records failures without throwing (1.1698ms) ✔ a timed-out gate is recorded as TIMEOUT rather than throwing (0.3746ms) ✔ a real subprocess failure captures both stdout and stderr (2747.6456ms) ✔ the gate list uses the frozen contract gate name openapi:validate (0.2628ms) ✔ --dry-run lists every gate and does not write the acceptance report (103.3603ms) ℹ tests 71 ℹ suites 0 ℹ pass 71 ℹ fail 0 ℹ cancelled 0 ℹ skipped 0 ℹ todo 0 ℹ duration_ms 3311.6936 |

## Environment probes

| gate | exit code | result | evidence tail |
| --- | --- | --- | --- |
| `docker-info` | 1 | NOT RUN (environment) | r Hardened Images (Docker Inc.)     Version:  v0.0.9     Path:     C:\Program Files\Docker\cli-plugins\docker-dhi.exe   extension: Manages Docker extensions (Docker Inc.)     Version:  v0.2.32     Path:     C:\Program Files\Docker\cli-plugins\docker-extension.exe   init: Creates Docker-related starter files for your project (Docker Inc.)     Version:  v1.4.1     Path:     C:\Program Files\Docker\cli-plugins\docker-init.exe   mcp: Docker MCP Plugin (Docker Inc.)     Version:  v0.44.2     Path:     C:\Program Files\Docker\cli-plugins\docker-mcp.exe   model: Docker Model Runner (Docker Inc.)     Version:  v1.2.9     Path:     C:\Program Files\Docker\cli-plugins\docker-model.exe   offload: Docker Offload (Docker Inc.)     Version:  v0.7.21     Path:     C:\Program Files\Docker\cli-plugins\docker-offload.exe   pass: Docker Pass Secrets Manager Plugin (beta) (Docker Inc.)     Version:  v0.3.2     Path:     C:\Program Files\Docker\cli-plugins\docker-pass.exe   sandbox: "docker sandbox" is deprecated, use Docker Sandboxes instead (Docker Inc.)     Version:  v0.13.0     Path:     C:\Program Files\Docker\cli-plugins\docker-sandbox.exe   scout: Docker Scout (Docker Inc.)     Version:  v1.27.0     Path:     C:\Program Files\Docker\cli-plugins\docker-scout.exe  Server: failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified. |

Environment probes are recorded separately and do not, by themselves, flip the product conclusion.

## Evidence

### backend-phpunit

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`
- exit code: 1
- result: FAIL

```text
'backend/../docs/openapi/openapi.yaml:16794',
+    1911 => 'backend/../docs/openapi/openapi.yaml:16801',
+    1912 => 'backend/../docs/openapi/openapi.yaml:17519',
+    1913 => 'backend/../docs/openapi/openapi.yaml:17523',
+    1914 => 'backend/../docs/openapi/openapi.yaml:17604',
+    1915 => 'backend/../docs/openapi/openapi.yaml:17609',
+    1916 => 'backend/../docs/openapi/openapi.yaml:17782',
+    1917 => 'backend/../docs/openapi/openapi.yaml:17867',
+    1918 => 'backend/../docs/openapi/openapi.yaml:17872',
+    1919 => 'backend/../docs/openapi/openapi.yaml:17917',
+    1920 => 'backend/../docs/openapi/openapi.yaml:18118',
+    1921 => 'backend/../docs/openapi/openapi.yaml:18126',
+    1922 => 'backend/../docs/openapi/openapi.yaml:18152',
+    1923 => 'backend/../docs/openapi/openapi.yaml:19165',
+    1924 => 'backend/../docs/openapi/openapi.yaml:19328',
+    1925 => 'backend/../docs/openapi/openapi.yaml:20252',
+    1926 => 'backend/../docs/openapi/openapi.yaml:20331',
+    1927 => 'backend/../docs/openapi/openapi.yaml:20336',
+    1928 => 'backend/../docs/openapi/openapi.yaml:20808',
+]

D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\tests\Contract\NoTenantNoLegalEntityTest.php:92

FAILURES!
Tests: 1136, Assertions: 52346, Failures: 1.

OpenApiSchemaContractTest: 0 success-schema violations across 323 operations

OpenApiSchemaContractTest: 0 error-set violations across 323 operations

OpenApiSchemaContractTest: 0 capability violations across 323 operations
```

### backend-pint

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test`
- exit code: 0
- result: PASS

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
  ............

  ──────────────────────────────────────────────────────────────────────────────────────────────────────────── Laravel  
    PASS   ................................................................................................ 1172 files
```

### backend-phpstan

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress`
- exit code: 0
- result: PASS

```text
[OK] No errors                                                                                                        

Note: Using configuration file D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\phpstan.neon.
```

### capability-audit

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan capability:audit`
- exit code: 0
- result: PASS

```text
ining/records training.record.read
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

### arch-test

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan arch:test`
- exit code: 0
- result: PASS

```text
Scanned 685 module PHP files.
WARN Platform/Migration/Adapters/DatabaseMigrationTarget.php:114 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
WARN Platform/Retention/RetentionService.php:65 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
WARN Platform/Retention/RetentionService.php:123 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
WARN Platform/Retention/RetentionService.php:138 [Platform] Cannot resolve dynamic table name to an owning module; review manually.
Warnings (non-blocking): 4
Module boundaries OK.
```

### backend-contract

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan test --compact --filter='ActionManifestParityTest|PagePermissionCoverageTest|NoTenantNoLegalEntityTest'`
- exit code: 1
- result: FAIL

```text
82',
+    1917 => 'backend/../docs/openapi/openapi.yaml:17867',
+    1918 => 'backend/../docs/openapi/openapi.yaml:17872',
+    1919 => 'backend/../docs/openapi/openapi.yaml:17917',
+    1920 => 'backend/../docs/openapi/openapi.yaml:18118',
+    1921 => 'backend/../docs/openapi/openapi.yaml:18126',
+    1922 => 'backend/../docs/openapi/openapi.yaml:18152',
+    1923 => 'backend/../docs/openapi/openapi.yaml:19165',
+    1924 => 'backend/../docs/openapi/openapi.yaml:19328',
+    1925 => 'backend/../docs/openapi/openapi.yaml:20252',
+    1926 => 'backend/../docs/openapi/openapi.yaml:20331',
+    1927 => 'backend/../docs/openapi/openapi.yaml:20336',
+    1928 => 'backend/../docs/openapi/openapi.yaml:20808',
+][39;22m

  at [32mtests\Contract\NoTenantNoLegalEntityTest.php[39m:[32m92[39m
     88▕                 ...$this->scanText($relativePath, (string) file_get_contents($explicitFile)),
     89▕             );
     90▕         }
     91▕ 
  ➜  92▕         $this->assertSame([], $violations, sprintf(
     93▕             "Tenant/legal-entity identifiers remain (%d):\n%s",
     94▕             count($violations),
     95▕             implode("\n", array_slice($violations, 0, 80)),
     96▕         ));

  [33m1   [39m[39;1mtests\Contract\NoTenantNoLegalEntityTest.php[39;22m:[39;1m92[39;22m


  [90mTests:[39m    [31;1m1 failed[39;22m[90m,[39m[39m [39m[32;1m16 passed[39;22m[90m (3367 assertions)[39m
  [90mDuration:[39m [39m3.27s[39m

exit 1
```

### frontend-typecheck

- command: `pnpm typecheck`
- exit code: 0
- result: PASS

```text
> factoryhr@1.0.0 typecheck D:\code\ai\factoryHR\.worktrees\v2.1-remediation
> node scripts/frontend-workspace.mjs typecheck


> @hrms/frontend@0.1.0 typecheck D:\code\ai\factoryHR\.worktrees\v2.1-remediation\frontend
> vue-tsc -b
```

### frontend-lint

- command: `pnpm lint`
- exit code: 0
- result: PASS

```text
> factoryhr@1.0.0 lint D:\code\ai\factoryHR\.worktrees\v2.1-remediation
> node scripts/frontend-workspace.mjs lint


> @hrms/frontend@0.1.0 lint D:\code\ai\factoryHR\.worktrees\v2.1-remediation\frontend
> eslint . --max-warnings=0
```

### frontend-unit

- command: `pnpm test:unit`
- exit code: 0
- result: PASS

```text
"/notices"

stderr | tests/unit/shellMenu.spec.ts > V1.1 expandable shell menu > ignores malformed or non-string persisted expansion values
[Vue Router warn]: No match found for location with path "/payroll/components"
[Vue Router warn]: No match found for location with path "/payroll/plans"
[Vue Router warn]: No match found for location with path "/payroll/employee-plans"
[Vue Router warn]: No match found for location with path "/payroll/statutory"
[Vue Router warn]: No match found for location with path "/payroll/inputs"
[Vue Router warn]: No match found for location with path "/payroll/batches"
[Vue Router warn]: No match found for location with path "/payroll/reviews"
[Vue Router warn]: No match found for location with path "/payroll/disbursement"
[Vue Router warn]: No match found for location with path "/payroll/payslips"
[Vue Router warn]: No match found for location with path "/payroll/adjustments"
[Vue Router warn]: No match found for location with path "/payroll/reports"
[Vue Router warn]: No match found for location with path "/notices"

stderr | tests/unit/talentPages.spec.ts > Wave 4 talent domain components > loads candidate evaluations for a scoped candidate without candidate PII leakage
[Vue Router warn]: No match found for location with path "/recruitment/candidates/evaluations?candidate_id=31"

stderr | tests/unit/dictionary.spec.ts > dictionary > returns a safe placeholder and records unknown codes
[dictionary] unknown code people.employeeStatus=UNKNOWN_CODE
```

### audit_page_components

- command: `node scripts/audit_page_components.mjs`
- exit code: 0
- result: PASS

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

### audit_frontend_modules

- command: `node scripts/audit_frontend_modules.mjs`
- exit code: 0
- result: PASS

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

### audit_action_manifest

- command: `node scripts/audit_action_manifest.mjs`
- exit code: 0
- result: PASS

```text
actions:check OK (160 pages, 3 write pages, 2 pages with actions, 3 actions, 170 explicit gaps, 1 public write exceptions)
```

### audit_page_model

- command: `node scripts/audit_page_model.mjs`
- exit code: 1
- result: FAIL

```text
{
  "ok": false,
  "failures": [
    "hr-employees-create: action-like path /hr/employees/create",
    "hr-departments-editor: action-like path /hr/departments/editor",
    "hr-positions-editor: action-like path /hr/positions/editor",
    "hr-contracts-editor: action-like path /hr/contracts/editor",
    "recruitment-requests-create: action-like path /recruitment/requests/create",
    "notices-editor: action-like path /notices/editor",
    "notices-archive: action-like path /notices/archive",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 员工档案",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 部门管理",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 岗位管理",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 员工异动",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: cross-module tab 劳动合同"
  ]
}
exit 1
```

### audit_ui_primitives

- command: `node scripts/audit_ui_primitives.mjs`
- exit code: 1
- result: FAIL

```text
{
  "ok": false,
  "failures": [
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw input (line 277)",
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw select (line 302)",
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: raw details (line 274)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw table (line 149)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw input (line 173)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: raw select (line 193)",
    "frontend/src/modules/talent/TalentForm.vue: raw table (line 1163)",
    "frontend/src/modules/talent/TalentForm.vue: raw input (line 1013)",
    "frontend/src/modules/talent/TalentForm.vue: raw select (line 1069)",
    "frontend/src/modules/talent/components/TalentWorkspacePage.vue: raw table (line 262)",
    "frontend/src/modules/talent/components/TalentWorkspacePage.vue: raw input (line 243)"
  ]
}
exit 1
```

### audit_user_copy

- command: `node scripts/audit_user_copy.mjs`
- exit code: 1
- result: FAIL

```text
{
  "ok": false,
  "failures": [
    "frontend/src/modules/people/components/ContractForm.vue: English enum 'ACTIVE' (line 29)",
    "frontend/src/modules/people/components/EmployeeCreateForm.vue: English enum 'FULL_TIME' (line 16)",
    "frontend/src/modules/people/components/MovementForms.vue: API path 'POST /v1/' (line 240)",
    "frontend/src/modules/people/components/MovementForms.vue: English enum 'FULL_TIME' (line 49)",
    "frontend/src/modules/people/components/PeopleWorkspace.vue: development marker 'Wave' (line 144)",
    "frontend/src/modules/people/components/PositionForms.vue: API path 'POST /v1/' (line 77)",
    "frontend/src/modules/people/components/PositionForms.vue: English enum 'ACTIVE' (line 40)",
    "frontend/src/modules/platform/components/PlatformWorkspace.vue: API path 'GET /v1/' (line 2230)",
    "frontend/src/modules/platform/components/PlatformWorkspace.vue: English enum 'ACTIVE' (line 152)",
    "frontend/src/modules/platform/components/PlatformWorkspace.vue: field profile code 'P1_INTERNAL' (line 170)",
    "frontend/src/modules/platform/pages/SystemRolesPage.vue: field profile code 'P0_PUBLIC' (line 15)",
    "frontend/src/modules/platform/pages/SystemUsersPage.vue: API path 'GET /v1/' (line 137)"
  ]
}
exit 1
```

### openapi-lint

- command: `node deploy/scripts/openapi-lint.mjs`
- exit code: 0
- result: PASS

```text
openapi-lint: OK (251 paths, 323 operations, 3.1.0)
```

### openapi:validate

- command: `pnpm openapi:validate`
- exit code: 0
- result: PASS

```text
> factoryhr@1.0.0 openapi:validate D:\code\ai\factoryHR\.worktrees\v2.1-remediation
> redocly lint --config .redocly.yaml docs/openapi/openapi.yaml

[1] docs\openapi\openapi.yaml:11772:7 at #/paths/~1v1~1health~1live/get/responses

Operation must have at least one `4XX` response.

11770 |   - System
11771 | security: []
11772 | responses:
      | ^^^^^^^^^
11773 |   "200":
11774 |     description: Process is alive; no dependency is checked.

Warning was generated by the operation-4xx-response rule.

Reference: https://redocly.com/docs/cli/rules/oas/operation-4xx-response


[2] docs\openapi\openapi.yaml:11788:7 at #/paths/~1v1~1health~1ready/get/responses

Operation must have at least one `4XX` response.

11786 |   - System
11787 | security: []
11788 | responses:
      | ^^^^^^^^^
11789 |   "200":
11790 |     description: Database, cache and storage are ready.

Warning was generated by the operation-4xx-response rule.

Reference: https://redocly.com/docs/cli/rules/oas/operation-4xx-response


validating docs\openapi\openapi.yaml...
docs\openapi\openapi.yaml: validated in 203ms

Woohoo! Your API description is valid. 🎉
You have 2 warnings.
```

### node-tests

- command: `node --test scripts/tests/*.test.mjs`
- exit code: 0
- result: PASS

```text
alidate reports a capability-guarded OpenAPI write that has no action (0.1247ms)
✔ validate honours only explicitly listed gaps (0.2272ms)
✔ rejects action capability that differs from OpenAPI (0.1393ms)
✔ every registry page has one disposition and a target (1.1098ms)
✔ missing decision fails closed (0.3797ms)
✔ missing target, owner or evidence fails closed (0.1799ms)
✔ invalid disposition fails closed (0.1205ms)
✔ deprecate requires evidence quoting written user approval (0.6302ms)
✔ registry parser handles compact and multiline page objects (0.9903ms)
✔ all-green product gates produce a strict YES even when docker is unavailable (0.9913ms)
✔ one red product gate produces NO — blocked with a bullet for each red gate (0.2144ms)
✔ only plan-approved carried gates produce YES with named owners (0.183ms)
✔ an environment failure is NOT RUN, listed separately, and is not a product red (0.1439ms)
✔ the report is deterministic for the same entries and snapshot commit (0.1883ms)
✔ runAcceptance executes selected gates sequentially and records failures without throwing (1.1698ms)
✔ a timed-out gate is recorded as TIMEOUT rather than throwing (0.3746ms)
✔ a real subprocess failure captures both stdout and stderr (2747.6456ms)
✔ the gate list uses the frozen contract gate name openapi:validate (0.2628ms)
✔ --dry-run lists every gate and does not write the acceptance report (103.3603ms)
ℹ tests 71
ℹ suites 0
ℹ pass 71
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 3311.6936
```

### docker-info

- command: `docker info`
- exit code: 1
- result: NOT RUN (environment)

```text
r Hardened Images (Docker Inc.)
    Version:  v0.0.9
    Path:     C:\Program Files\Docker\cli-plugins\docker-dhi.exe
  extension: Manages Docker extensions (Docker Inc.)
    Version:  v0.2.32
    Path:     C:\Program Files\Docker\cli-plugins\docker-extension.exe
  init: Creates Docker-related starter files for your project (Docker Inc.)
    Version:  v1.4.1
    Path:     C:\Program Files\Docker\cli-plugins\docker-init.exe
  mcp: Docker MCP Plugin (Docker Inc.)
    Version:  v0.44.2
    Path:     C:\Program Files\Docker\cli-plugins\docker-mcp.exe
  model: Docker Model Runner (Docker Inc.)
    Version:  v1.2.9
    Path:     C:\Program Files\Docker\cli-plugins\docker-model.exe
  offload: Docker Offload (Docker Inc.)
    Version:  v0.7.21
    Path:     C:\Program Files\Docker\cli-plugins\docker-offload.exe
  pass: Docker Pass Secrets Manager Plugin (beta) (Docker Inc.)
    Version:  v0.3.2
    Path:     C:\Program Files\Docker\cli-plugins\docker-pass.exe
  sandbox: "docker sandbox" is deprecated, use Docker Sandboxes instead (Docker Inc.)
    Version:  v0.13.0
    Path:     C:\Program Files\Docker\cli-plugins\docker-sandbox.exe
  scout: Docker Scout (Docker Inc.)
    Version:  v1.27.0
    Path:     C:\Program Files\Docker\cli-plugins\docker-scout.exe

Server:
failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
```

## Conclusion

**YES — Volume 1 contracts closed; carried gates owned by Volume 2/3**

- `backend-phpunit`: expected failure: ContractNoTenantNoLegalEntityTest (Volume 2 Tasks 2/3).
- `backend-contract`: expected failure: NoTenantNoLegalEntityTest (Volume 2 Tasks 2/3).
- `audit_page_model`: legacy pages/tabs (Volume 2 Task 6-8, Volume 3 Task 2-17).
- `audit_ui_primitives`: legacy raw controls (Volume 2 Task 6-8, Volume 3 Task 2-17).
- `audit_user_copy`: legacy user-facing copy (Volume 2 Task 6-8, Volume 3 Task 2-17).

These are the plan-approved Volume 1 exceptions. Volume 1 does not claim them as passing.

