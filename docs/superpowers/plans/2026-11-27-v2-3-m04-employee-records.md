# FactoryHR V2.3 M04｜员工档案 — 详细实施计划
> **状态：** Proposed — 待用户确认；批准前不得执行。
> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development 或 superpowers:executing-plans；步骤用 `- [ ]` 跟踪。
**Goal:** 收敛为 `/hr/employees` 与 `/hr/employees/:id`，八个子资源路由折入详情 Tab，导入成为列表动作，并闭环导出、子档案 retire、资料变更审批。
**Architecture:** People 独占 Person/Employment/Assignment/Terms 与附属档案写入权；详情只按同一 employment_id 查询；导出复用列表 as-of 与范围谓词；变更经 Workflow Case 与 People 本地 Effect 生效。
**Tech Stack:** Laravel 13 / PHP 8.3 / Vue 3.5 / TypeScript / Element Plus / PHPUnit / Vitest / Playwright / OpenAPI 3.1。

## Global Constraints
- 旧 URL 不重定向，删除后 404；每个被删入口在矩阵有目的地。
- 详情只容纳员工本人附属档案，不混入薪资、考勤、绩效。
- 写动作必须有 capability、OpenAPI operation、动作清单和 403 负测；新 API 标“新增”并同步契约。
- 金额用整数最小单位；时间 UTC；业务日期按公司时区与共享日历。
- 原子入职、归档/恢复、版本化 PATCH 已满足，直接复用；未执行测试写 `NOT RUN`。
## 0. 模块事实
### 0.1 KEEP 页面/文件
| 页面 | 路由 | 真实文件 |
|---|---|---|
| 员工列表 | `/hr/employees` | `frontend/src/modules/people/employees/pages/EmployeeListPage.vue` |
| 员工详情 | `/hr/employees/:id` | `frontend/src/modules/people/employees/pages/EmployeeDetailPage.vue` |
| Tab/子档案/附件/变更 | 组件 | `frontend/src/modules/people/employees/components/{EmployeeTabs,EmployeeProfileSection,EmployeeFileList,EmployeeChangeList}.vue` |
后端保留 `EmployeeController`、`EmployeeProfileController`、`EmployeeProfileService`、`AsOfEmployeeQuery`、`CreateEmployeeService`。

### 0.2 FOLD 删除清单与目的地
| 原路由 | 当前文件 | 目的地 |
|---|---|---|
| `/hr/employees/:id/education` | `frontend/src/modules/people/pages/EmployeeEducationPage.vue` | 详情 `?tab=education` |
| `/hr/employees/:id/work-history` | `.../EmployeeWorkHistoryPage.vue` | 详情 `?tab=experience` |
| `/hr/employees/:id/contacts` | `.../EmployeeContactsPage.vue` | 详情 `?tab=contacts` |
| `/hr/employees/:id/credentials` | `.../EmployeeCredentialsPage.vue` | 详情 `?tab=credentials` |
| `/hr/employees/:id/history` | `.../EmployeeHistoryPage.vue` | 详情 `?tab=employment` |
| `/hr/employees/:id/files` | `.../EmployeeFilesPage.vue` | 详情 `?tab=credentials` 附件区 |
| `/hr/employees/:id/changes` | `.../EmployeeChangesPage.vue` | 详情 `?tab=changes` |
| `/hr/employees/imports` | `.../EmployeeImportsPage.vue` | 列表“导入结果” |

### 0.3 当前缺陷
1. 八个旧页仍由 `router/registry.ts`、`pages/index.ts`、`catalog.ts` 注册，且多为 `PeopleWorkspace` 包装。
2. `EmployeeDetailPage.vue` 在 `EmployeeTabs` 外又渲染 `EmployeeProfileSection`，教育/经历/联系人/证照/变更出现双表。
3. `people.employee.export` 已有，但 `GET /v1/employees/export` 与 OpenAPI operation 缺失（`docs/audit/2026-11-26-v2-action-gaps.json`）。
4. `EmployeeProfileController` 无子档案 retire；profile 表已有 `superseded_at`，无需新迁移。
5. `EmployeeProfileService::submitChangeRequest()` 只改 `SUBMITTED`，不建 Case；`employee_change_requests.case_id` 为空，字段已存在。
6. `EmployeeChangeList.vue` 链接 `/cases/{id}`，实际详情是 `/workflow/cases/:id`。
7. `/v1/employees/{employmentId}/imports` 是员工路径下的公司级批次读取，列表无入口；HTTP 文件校验/提交命令不存在。

## 1. 目标契约
### 1.1 页面结构
`/hr/employees`：筛选（业务日期、组织、关键词、含归档）→ 工具栏（新建、导入结果、导出、刷新）→ 表格 → 分页；行内只放“查看”。`/hr/employees/:id?tab=`：摘要卡 + `overview|employment|contracts|credentials|education|experience|contacts|changes`；Tab 写回 query，刷新/返回列表保留筛选；`files|history|work-history` 只作 query alias。

### 1.2 DTO/API
| 能力 | API | 状态 | capability |
|---|---|---|---|
| 列表/详情 | `GET /v1/employees`、`GET /v1/employees/{employment_id}` | 现有 | `people.employee.read` |
| 原子入职 | `POST /v1/employees/onboard` | 现有 | `people.employee.manage` |
| 归档/恢复 | `DELETE /v1/employees/{employment_id}`、`POST .../restore` | 现有 | `people.employee.archive/restore` |
| 子档案 CRUD | `/v1/employees/{employmentId}/{education|experiences|contacts|certificates}` | 现有 | read/manage |
| 子档案 retire | `DELETE /v1/employees/{employmentId}/{resource}/{id}` | **新增** | `people.employee.manage` |
| 员工导出 | `GET /v1/employees/export?as_of=YYYY-MM-DD` | **新增** | `people.employee.export` |
| 导入批次 | `GET /v1/employee-imports?page=1&page_size=20` | **新增** | `people.employee.manage` |
| 变更提交 | `POST /v1/employees/{employmentId}/change_requests/{id}/submit` | 现有，补 Case | `people.employee.manage` |

子档案 DTO `{id,employment_id,version?,superseded_at?,recorded_at?,fields...}`；联系人 phone 保持 `{display,masked,profile,revealable,exportable}`；导出仅含字段策略允许列。

### 1.3 状态机
```text
Employment: ACTIVE --archive(reason)--> ARCHIVED --restore--> ACTIVE
Sub-profile: ACTIVE --revise--> SUPERSEDED + new ACTIVE version
Sub-profile: ACTIVE --retire(reason)--> RETIRED (superseded_at set)
ChangeRequest: DRAFT --submit--> SUBMITTED --> IN_APPROVAL --> APPROVED|REJECTED
```
只有 APPROVED effect 可调 `revise()`；重复决定/effect 只能稳定 409 或幂等重放；actor、subject employment、approver 分离。

### 1.4 权限
| 动作 | capability | 数据范围 |
|---|---|---|
| 列表/详情/子档案读 | `people.employee.read` | SELF/MANAGED/ORG_UNIT/ORG_SUBTREE/COMPANY |
| 新建/编辑/retire/导入 | `people.employee.manage` | ORG_UNIT/ORG_SUBTREE/COMPANY |
| 归档/恢复 | `people.employee.archive/restore` | ORG_UNIT/ORG_SUBTREE/COMPANY |
| 导出 | `people.employee.export` | 读取范围 + 字段可导出 |
| 附件下载 | `people.document.download` | 与员工读取范围一致 |

## 2. 任务
### Task 1: 冻结详情 Tab 并消除双表
**Files:** Modify `frontend/src/modules/people/employees/pages/EmployeeDetailPage.vue`、`components/EmployeeTabs.vue`、`detailApi.ts`; Test `frontend/tests/unit/employeeTabsContract.spec.ts`
**Interfaces:** Consumes `getEmployee()`、`loadEmployeeTab()`、`EmployeeProfileSection`、`EmployeeFileList`、`EmployeeChangeList`。Produces 八值 `EmployeeTabKey`；alias `files→credentials`、`history→employment`、`work-history→experience`。
- [ ] **Step 1: 写失败测试**
```ts
const s = readFileSync('src/modules/people/employees/pages/EmployeeDetailPage.vue', 'utf8')
expect(s).toContain("files: 'credentials'"); expect(s).toContain("history: 'employment'")
expect(s).toContain('<EmployeeProfileSection')
```
- [ ] **Step 2: 运行确认失败**
```bash
cd frontend && pnpm vitest run tests/unit/employeeTabsContract.spec.ts
```
Expected: FAIL，缺少 `history` alias。
- [ ] **Step 3: 实现单编辑区**
```ts
const profileResource = computed<'education' | 'experiences' | 'contacts' | null>(() => {
  if (activeTab.value === 'education') return 'education'
  if (activeTab.value === 'experience') return 'experiences'
  if (activeTab.value === 'contacts') return 'contacts'
  return null
})
```
`EmployeeTabs.vue` 只保留 timeline/contracts/documents 摘要；`credentials` 渲染证照 + 附件；`changes` 只渲染 `EmployeeChangeList`。
- [ ] **Step 4: 运行确认通过**
```bash
cd frontend && pnpm vitest run tests/unit/employeeTabsContract.spec.ts tests/unit/EmployeeDetailPage.spec.ts
```
Expected: PASS，Tab 切换只有一套数据表。
- [ ] **Step 5: 提交**
```bash
git add frontend/src/modules/people/employees frontend/tests/unit/employeeTabsContract.spec.ts
git commit -m "refactor(m04): freeze employee detail tab contract"
```
### Task 2: 版本化子档案 retire
**Files:** Modify `backend/routes/api.php`、`backend/app/Modules/People/Http/Controllers/EmployeeProfileController.php`、`Services/EmployeeProfileService.php`、`frontend/src/modules/people/employees/components/EmployeeProfileSection.vue`、`docs/openapi/openapi.yaml`、`backend/tests/Authorization/endpoint_matrix.php`; Test `backend/tests/Feature/People/EmployeeProfileRetireTest.php`、`frontend/tests/unit/employeeProfileRetire.spec.ts`
**Interfaces:** Consumes `EmployeeProfileService`、`superseded_at`、`AuditRecorder`。Produces `DELETE /v1/employees/{employmentId}/{resource}/{id}` **新增** → `{retired:true,id,employment_id,superseded_at}`；body `{reason,effective_from}`。
- [ ] **Step 1: 写失败测试**
```php
$s = $this->scenario(); $e = $s->employment($s->person(), 'RET-0001'); $s->assignment($e, $s->orgUnit('RET-ORG'));
$id = (int) $this->postJson("/v1/employees/{$e->id}/education", ['school' => '合成大学', 'degree' => 'BACHELOR', 'start_date' => '2010-09-01'])->assertCreated()->json('data.id');
$this->deleteJson("/v1/employees/{$e->id}/education/{$id}", ['reason' => '合成重复记录', 'effective_from' => '2026-11-27'])->assertOk()->assertJsonPath('data.retired', true);
$this->assertDatabaseHas('audit_events', ['action' => 'people.profile.retired']);
```
- [ ] **Step 2: 运行确认失败**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=EmployeeProfileRetireTest
```
Expected: FAIL，DELETE 路由不存在。
- [ ] **Step 3: 实现受控 retire**
```php
Route::delete('/{profileResource}/{id}', [EmployeeProfileController::class, 'retire'])
    ->whereNumber('employmentId')->whereNumber('id')
    ->defaults('profileResource', $profileResource)
    ->middleware('capability:people.employee.manage')
    ->name('employees.profile.'.$profileResource.'.retire');
```
Service 在同一事务锁定当前版本，写 `superseded_at=$clock->now()` 与审计，不物理删除；UI 行操作只在 `ActionGate capability="people.employee.manage"` 内显示“删除记录”Dialog。
- [ ] **Step 4: 运行确认通过**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=EmployeeProfileRetireTest
cd ../frontend && pnpm vitest run tests/unit/employeeProfileRetire.spec.ts
```
Expected: PASS，列表隐藏 retired 行，历史仍在。
- [ ] **Step 5: 提交**
```bash
git add backend/routes/api.php backend/app/Modules/People backend/tests/Feature/People/EmployeeProfileRetireTest.php backend/tests/Authorization/endpoint_matrix.php docs/openapi/openapi.yaml frontend/src/modules/people/employees/components/EmployeeProfileSection.vue frontend/tests/unit/employeeProfileRetire.spec.ts
git commit -m "feat(m04): add controlled sub-profile retirement"
```
### Task 3: 导入结果与导出成为列表动作
**Files:** Create `backend/app/Modules/People/Http/Controllers/EmployeeImportController.php`、`frontend/src/modules/people/employees/components/EmployeeImportDrawer.vue`; Modify `backend/routes/api.php`、`backend/app/Modules/People/Http/Controllers/EmployeeController.php`、`Queries/AsOfEmployeeQuery.php`、`frontend/src/modules/people/employees/pages/EmployeeListPage.vue`、`api.ts`、`docs/openapi/openapi.yaml`、`docs/generated/action-input.json`、`backend/tests/Authorization/endpoint_matrix.php`; Test `backend/tests/Feature/People/EmployeeImportListTest.php`、`EmployeeExportTest.php`、`frontend/tests/unit/employeeImportExportActions.spec.ts`
**Interfaces:** Consumes `EmployeeImportBatch`、`AsOfEmployeeQuery` 范围谓词、字段策略、`people.employee.manage/export`。Produces **新增** `GET /v1/employee-imports` list envelope；**新增** `GET /v1/employees/export` 返回 `text/csv; charset=UTF-8`；动作 `hr.employees.export`。
- [ ] **Step 1: 写失败测试**
```php
EmployeeImportBatch::query()->create(['uploaded_by'=>10,'status'=>'VALIDATING','original_name'=>'synthetic.csv','total_rows'=>3,'valid_rows'=>2,'failed_rows'=>1,'errors_json'=>[]]);
$this->getJson('/v1/employee-imports?page=1&page_size=20')->assertOk()->assertJsonPath('data.total',1);
$this->actingAsUserWithCapabilities(['people.employee.read','people.employee.export']);
$this->get('/v1/employees/export?as_of=2026-11-23&org_unit_id=10')->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8')
  ->assertDontSee('national_id');
```
- [ ] **Step 2: 运行确认失败**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter='EmployeeImportListTest|EmployeeExportTest'
```
Expected: FAIL，两个路由不存在。
- [ ] **Step 3: 实现两个列表动作**
```php
$query = EmployeeImportBatch::query()->orderByDesc('id'); $total = (int) (clone $query)->count();
$items = $query->forPage($page,$pageSize)->get()->map(fn ($b) => ['id'=>(int)$b->id,'status'=>(string)$b->status,'original_name'=>(string)$b->original_name,'total_rows'=>(int)$b->total_rows,'valid_rows'=>(int)$b->valid_rows,'failed_rows'=>(int)$b->failed_rows,'errors'=>(array)$b->errors_json])->all();
return ApiResponse::ok(ApiResponse::list($items,$page,$pageSize,$total));
```
```php
Route::get('/export',[EmployeeController::class,'export'])->middleware('capability:people.employee.export,COMPANY')->name('employees.export');
```
`export()` 复用 `AsOfEmployeeQuery` 范围构造器，列白名单 `employee_no,person_name,status,hire_date,org_unit_name,position_title`，用 `streamDownload`/`fputcsv` 输出。列表工具栏加“导入结果”和“导出”；Drawer 只显示真实批次；导出包 `ActionGate capability="people.employee.export"`。
- [ ] **Step 4: 运行确认通过**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter='EmployeeImportListTest|EmployeeExportTest'
cd ../frontend && pnpm vitest run tests/unit/employeeImportExportActions.spec.ts
```
Expected: PASS；撤权后按钮消失，直接请求 403。
- [ ] **Step 5: 提交**
```bash
git add backend/app/Modules/People backend/routes/api.php backend/tests/Feature/People/EmployeeImportListTest.php backend/tests/Feature/People/EmployeeExportTest.php backend/tests/Authorization/endpoint_matrix.php docs/openapi/openapi.yaml docs/generated/action-input.json frontend/src/modules/people/employees frontend/tests/unit/employeeImportExportActions.spec.ts
git commit -m "feat(m04): add employee import and export list actions"
```
### Task 4: 资料变更接入 Workflow Case
**Files:** Create `backend/app/Modules/People/Contracts/EmployeeChangeApprovalPort.php`、`Adapters/EmployeeChangeWorkflowAdapter.php`、`EffectHandlers/EmployeeProfileChangeEffectHandler.php`; Modify `Http/Controllers/EmployeeProfileController.php`、`Services/EmployeeProfileService.php`、`Providers/PeopleServiceProvider.php`、`frontend/src/modules/people/employees/components/EmployeeChangeList.vue`、`docs/openapi/openapi.yaml`; Test `backend/tests/Feature/People/EmployeeChangeRequestWorkflowTest.php`、`frontend/tests/unit/employeeChangeWorkflow.spec.ts`
**Interfaces:** Consumes `CaseCommandPort::open()`、`DomainEffectHandler`、`employee_change_requests.case_id`。Produces submit `{id,status:'SUBMITTED',case_id,case_no}`；effect `people.employee.change.apply` → `revise()`；链接 `/workflow/cases/{case_id}`。
- [ ] **Step 1: 写失败测试**
```php
$s = $this->scenario(); $e = $s->employment($s->person(), 'CHG-WF-1'); $s->assignment($e, $s->orgUnit('CHG-WF-ORG'));
$id = (int) $this->postJson("/v1/employees/{$e->id}/change_requests", ['reason' => '合成变更', 'diff' => ['resource' => 'education', 'record_id' => 7, 'changes' => ['major' => '合成专业']]])->assertCreated()->json('data.id');
$submitted = $this->postJson("/v1/employees/{$e->id}/change_requests/{$id}/submit", [], ['Idempotency-Key' => 'chg-wf-1'])->assertOk();
$caseId = (int) $submitted->json('data.case_id'); $this->assertGreaterThan(0, $caseId);
$this->assertDatabaseHas('employee_change_requests', ['id' => $id, 'case_id' => $caseId]);
```
- [ ] **Step 2: 运行确认失败**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=EmployeeChangeRequestWorkflowTest
```
Expected: FAIL，`case_id` 为空。
- [ ] **Step 3: 实现端口、effect 与深链**
```php
$case = $this->cases->open(principal: $principal, type: 'EMPLOYEE_PROFILE_CHANGE', subjectType: 'employment',
  subjectId: $employmentId, title: '员工资料变更', businessDate: $businessDate, submit: true,
  payload: ['change_request_id' => $id, 'employment_id' => $employmentId, 'resource' => $diff['resource'],
    'record_id' => (int) $diff['record_id'], 'changes' => (array) $diff['changes']]);
```
`EmployeeProfileChangeEffectHandler::supports('people.employee.change.apply')`；仅 case 已批准时对白名单 resource 调 `revise()`，写回 `status/decided_at/decided_by`；前端链接改为 `/workflow/cases/${caseId}`。
- [ ] **Step 4: 运行确认通过**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=EmployeeChangeRequestWorkflowTest
cd ../frontend && pnpm vitest run tests/unit/employeeChangeWorkflow.spec.ts
```
Expected: PASS；同键重放同一 case，不同键二次提交 409。
- [ ] **Step 5: 提交**
```bash
git add backend/app/Modules/People backend/tests/Feature/People/EmployeeChangeRequestWorkflowTest.php docs/openapi/openapi.yaml frontend/src/modules/people/employees/components/EmployeeChangeList.vue frontend/tests/unit/employeeChangeWorkflow.spec.ts
git commit -m "feat(m04): attach employee change requests to workflow"
```
### Task 5: 删除八个旧入口并跑门禁
**Files:** Delete `frontend/src/modules/people/pages/Employee{Education,WorkHistory,Contacts,Credentials,History,Files,Changes,Imports}Page.vue`; Modify `pages/index.ts`、`catalog.ts`、`frontend/src/router/registry.ts`、`backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`、`frontend/tests/unit/peoplePageModules.spec.ts`; Test `frontend/tests/unit/employeeSubroutesRemoved.spec.ts`、`frontend/tests/e2e/real/employee-profile.spec.ts`
**Interfaces:** Consumes Task 1–5 的 Tab、导入、导出、retire、审批 effect。Produces People 只保留 `hr/employees/index`、`hr/employees/by-id` loader；旧 registry/catalog/actions 清零。
- [ ] **Step 1: 写失败测试**
```ts
const r = readFileSync('src/router/registry.ts', 'utf8')
for (const s of ['education','work-history','contacts','credentials','history','files','changes','imports']) {
  expect(r).not.toContain(`path: '/hr/employees/:id/${s}'`)
}
expect(r).toContain("path: '/hr/employees/:id'")
```
- [ ] **Step 2: 运行确认失败**
```bash
cd frontend && pnpm vitest run tests/unit/employeeSubroutesRemoved.spec.ts
```
Expected: FAIL，旧 entry 仍存在。
- [ ] **Step 3: 删除旧页与注册**
```bash
cd frontend/src/modules/people/pages && git rm EmployeeEducationPage.vue EmployeeWorkHistoryPage.vue EmployeeContactsPage.vue EmployeeCredentialsPage.vue EmployeeHistoryPage.vue EmployeeFilesPage.vue EmployeeChangesPage.vue EmployeeImportsPage.vue
```
删除 catalog config、barrel key、registry 行和 PermissionResourceCatalog 八个 page entries；`peoplePageModules.spec.ts` 从 35 个 loader 改为 27。
- [ ] **Step 4: 残留扫描、单测、真实 E2E、门禁**
```bash
cd frontend
rg -n "EmployeeEducationPage|EmployeeWorkHistoryPage|EmployeeContactsPage|EmployeeCredentialsPage|EmployeeHistoryPage|EmployeeFilesPage|EmployeeChangesPage|EmployeeImportsPage|/hr/employees/imports" src tests
pnpm vitest run tests/unit/employeeSubroutesRemoved.spec.ts tests/unit/peoplePageModules.spec.ts
pnpm playwright test --config=playwright.real.config.ts tests/e2e/real/employee-profile.spec.ts
cd .. && pnpm gate:all
```
Expected: `rg` 0 行；Vitest PASS；E2E 与 gate 实际 PASS 才记录，环境缺失写 `NOT RUN`。
- [ ] **Step 5: 运行确认通过**
```bash
cd frontend && pnpm vitest run tests/unit/employeeSubroutesRemoved.spec.ts tests/unit/peoplePageModules.spec.ts
```
Expected: PASS，旧入口 0 残留。
- [ ] **Step 6: 提交**
```bash
git add -A frontend/src/modules/people frontend/src/router frontend/tests backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php docs
git commit -m "refactor(m04): fold employee subresource routes into detail tabs"
```
## 3. 权限负测
| 场景 | 请求 | 预期 |
|---|---|---|
| 未登录读详情 | `GET /v1/employees/1` | 401 |
| 无读能力 | `GET /v1/employees` | 403，无行 |
| 跨组织读详情 | `GET /v1/employees/{outOfScope}` | 404，不泄露存在性 |
| 无 manage 写子档案 | `POST /v1/employees/1/education` | 403，不新增行 |
| 无 archive 归档 | `DELETE /v1/employees/1` | 403，`archived_at` 不变 |
| 无 export 导出 | `GET /v1/employees/export` | 403，无文件 |
| 含 P3 字段导出 | `GET /v1/employees/export?fields=national_id` | 422/403，审计无明文 |
| 无 document.download | `GET /v1/documents/1/signed-download` | 403 |
| 无 workflow.case.read | `/workflow/cases/1` | 页面拒绝或 403 |
| 撤权后旧页 retire | `DELETE .../education/1` | 403，版本不变 |
| 变更未批准 effect | 直接 effect | 409，不写档案 |
## 4. 退出检查
- [ ] `/hr/employees` 可筛选、新建、导入结果、导出、归档/恢复；八个 Tab 均由同一 employment_id 驱动，`?tab=` 可恢复，旧 alias 不生成路由。
- [ ] 八个旧文件、catalog config、barrel loader、registry 行、PermissionResourceCatalog 页面 0 残留；三个新增 API 已登记全部契约与测试。
- [ ] 变更提交产生真实 `case_id`，批准 effect 只应用一次，深链为 `/workflow/cases/:id`；既有原子入职、归档/恢复、版本化 PATCH 未回退；权限负测均稳定拒绝。
- [ ] `pnpm gate:all` 实际 PASS；E2E 截图/trace 已归档；未执行项 `NOT RUN`。
