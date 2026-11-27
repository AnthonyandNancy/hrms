# FactoryHR V2.3 M05｜部门与组织结构 — 详细实施计划
> **状态：** Proposed — 待用户确认；批准前不得执行。
> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development 或 superpowers:executing-plans；步骤使用 `- [ ]` 跟踪。

**Goal:** 将五个部门入口收敛为 `/hr/departments` 唯一工作区；部门列表、成员、迁移、编制成为同一选中部门的视图/动作，并把两套组织写路由收敛为一个 Organization writer。
**Architecture:** Organization 独占 `org_units`、组织闭包与部门写命令；People 只通过 `DepartmentMembershipPort`、`DepartmentStaffingDirectory` 提供成员和编制投影。读取按 as-of 过滤，移动只结束/追加闭包，不重写 id。
**Tech Stack:** Laravel 13 / PHP 8.3 / Vue 3.5 / TypeScript / Element Plus / PHPUnit / Vitest / Playwright / OpenAPI 3.1。

## Global Constraints
- `/hr/departments/list`、`/members`、`/move`、`/staffing` 全部删除，不保留重定向。
- 部门写命令只允许一个入口：保留 `POST/PATCH /v1/departments`，`/v1/org-units` 只作读树与受控 move 命令的兼容边界，不再提供第二个 create writer。
- 树不得成环、部门编码唯一、移动必须带幂等键/审计/生效日；成员数据来自 assignment，不另建人岗。
- 新 API 标“新增”并同步 OpenAPI、endpoint matrix、capability、动作清单、生成类型与测试。
- 未执行测试在证据中写 `NOT RUN`，不得写“通过”。
## 0. 模块事实
### 0.1 KEEP 页面/文件
| 页面 | 路由 | 文件 |
|---|---|---|
| 部门与组织 | `/hr/departments` | `frontend/src/modules/people/departments/pages/DepartmentWorkspacePage.vue` |
| 组件 | 树/表单/成员 | `frontend/src/modules/people/departments/components/{DepartmentTree,DepartmentFormDrawer,DepartmentMembersPanel}.vue` |
| 后端 | 读/写 | `backend/app/Modules/Organization/Http/Controllers/DepartmentController.php`、`backend/app/Modules/Organization/Services/OrgUnitService.php` |

### 0.2 FOLD 删除清单与目的地
| 原路由 | 当前文件 | 能力目的地 |
|---|---|---|
| `/hr/departments/list` | `frontend/src/modules/people/pages/DepartmentListPage.vue` | 工作区“列表视图” |
| `/hr/departments/members` | `.../DepartmentMembersPage.vue` | 选中部门“成员”Tab |
| `/hr/departments/move` | `.../DepartmentMovePage.vue` | 选中部门“移动部门”Drawer |
| `/hr/departments/staffing` | `.../DepartmentStaffingPage.vue` | 选中部门“编制”Tab |

### 0.3 当前缺陷（源码证据）
1. 四个旧页均由 `frontend/src/router/registry.ts` 和 `frontend/src/modules/people/catalog.ts` 注册，旧页只渲染 `PeopleWorkspace`。
2. 两套写入：`POST /v1/departments` → `DepartmentController::store`，`POST /v1/org-units` → `OrgUnitController::store`，两者都调用 `OrgUnitService::open()`，却校验/返回字段不同。
3. `DepartmentController::update()` 只接受 `new_parent_id/effective_from` 并调用 `move()`，而 `frontend/src/modules/people/departments/api.ts` 的 `updateDepartment()` 发送 `code/name/type/valid_from/valid_to/manager_employment_id`；页面“编辑部门”与接口契约不一致。
4. `OrgUnitController::store()` 使用 `valid_from` 可选、`status` 默认值；`DepartmentController::store()` 要求 `valid_from`，且团队/成本中心/共享服务类型都写同一表。两处审计 path 不同。
5. `frontend/src/modules/people/departments/components/DepartmentFormDrawer.vue` 提交 `manager_employment_id`，但 `OrgUnitController::store()` 完全没有接收/持久化该字段；`department.manager_employment_id` 只能读空。
6. `DepartmentWorkspacePage.vue` 已有树、选中部门、成员预览和移动向导，但这些能力没有成为稳定 Tab/动作，旧入口仍独立存在。
7. 移动影响预览已有 `GET /v1/org-units/{id}/impact`，但仅按 `org.unit.manage` 与 `as_of` 取数；没有把“审批中/组织权限”作为显式预览字段。

## 1. 目标契约
### 1.1 页面结构
`/hr/departments` 左侧组织树（含搜索、展开、选中），右侧选中部门 Header（编码/名称/状态/上级/负责人）+ Tab：`overview`、`list`、`members`、`staffing`；动作：添加下级、编辑、移动、刷新。Tab/选中部门写 query（`?dept=:id&tab=`），刷新可恢复；`list` 是同一部门的表格视图，不请求第二套事实。

### 1.2 DTO/API
| 能力 | API | 状态 | capability |
|---|---|---|---|
| 组织树/部门列表 | `GET /v1/org-units/tree`、`GET /v1/departments` | 现有 | `org.tree.read` + `org.unit.read` |
| 部门创建/编辑 | `POST /v1/departments`、`PATCH /v1/departments/{id}` | 现有，收敛唯一 writer | `org.unit.manage` |
| 成员 | `GET /v1/departments/{id}/members` | 现有 | `org.unit.read` |
| 成员迁移/移除 | `POST /v1/departments/{id}/members`、`DELETE .../{employmentId}` | 现有 | `org.unit.manage` |
| 编制 | `GET /v1/departments/staffing` | 现有 | `org.unit.read` |
| 移动影响 | `GET /v1/org-units/{id}/impact` | 现有 | `org.unit.manage` |
| 移动确认 | `POST /v1/org-units/{id}/move` | 现有，保留唯一 move writer | `org.unit.manage` |
| 旧 writer 退役 | `POST /v1/org-units` | 删除 create 语义 | 不新增替代 API |

部门 DTO：`{id,code,name,type,parent_id,status,valid_from,valid_to,manager_employment_id?,headcount_limit?,headcount?}`；`manager_employment_id` 若数据库没有真实列，只能作为**新增迁移**补 `manager_employment_id`，不得前端假装保存。编制 DTO：`{org_unit_id,code,name,headcount_limit,staffed,vacancies,overstaffed,version?}`；当前表无“编制历史版本”表，若保留“历史版本”展示，必须**新增 migration** `org_unit_headcount_versions`（org_unit_id, headcount_limit, effective_from, recorded_at, recorded_by），不得复用员工数计算。

### 1.3 状态机
```text
OrgUnit: ACTIVE --deactivate--> INACTIVE
OrgUnit: parent P --move(effective_from,new_parent)--> close old closure at date + append new closure; id unchanged
Member: assignment ACTIVE --move/remove(effective_from)--> historical valid_to set + optional new assignment
Headcount: version N --publish(effective_from)--> version N+1; history append-only
```
所有写命令必须带 `Idempotency-Key`；重复移动同一 payload 返回原结果；环检测、跨父级/范围错误稳定 409/422。

### 1.4 权限
| 动作 | capability | 范围 |
|---|---|---|
| 树/列表/成员/编制读 | `org.tree.read` + `org.unit.read` | ORG_UNIT/ORG_SUBTREE/COMPANY |
| 创建/编辑/移动/成员迁移/移除/编制维护 | `org.unit.manage` | ORG_UNIT/ORG_SUBTREE/COMPANY |
| 组织导出（若补） | `org.unit.export` | 树/列表同范围 + 字段白名单 |

## 2. 任务
### Task 1: 明确并冻结唯一 Organization writer
**Files:** Modify `backend/routes/api.php`、`backend/app/Modules/Organization/Http/Controllers/DepartmentController.php`、`OrgUnitController.php`、`Services/OrgUnitService.php`、`frontend/src/modules/people/departments/api.ts`、`docs/openapi/openapi.yaml`; Test `backend/tests/Feature/Organization/DepartmentSingleWriterTest.php`、`frontend/tests/unit/departmentSingleWriter.spec.ts`
**Interfaces:** Consumes `OrgUnitService::open()/move()`、`DepartmentMembershipPort`。Produces `POST/PATCH /v1/departments` 唯一 create/update writer；`POST /v1/org-units` 删除，`GET /v1/org-units/tree`、`POST /v1/org-units/{id}/move`、`GET /v1/org-units/{id}/impact` 保留。
- [ ] **Step 1: 写失败测试**
```php
public function test_department_create_is_the_only_org_unit_create_writer(): void
{
    $this->postJson('/v1/departments', ['code' => 'ORG-1', 'name' => '合成一部', 'type' => 'DEPARTMENT', 'valid_from' => '2026-01-01'])->assertCreated();
    $this->postJson('/v1/org-units', ['code' => 'ORG-2', 'name' => '第二写入口', 'type' => 'DEPARTMENT'])->assertNotFound();
    $this->assertDatabaseMissing('org_units', ['code' => 'ORG-2']);
}
```
- [ ] **Step 2: 运行确认失败**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=DepartmentSingleWriterTest
```
Expected: FAIL，`POST /v1/org-units` 仍可创建。
- [ ] **Step 3: 收敛 writer**
```php
// routes/api.php: 删除 Route::post('/', [OrgUnitController::class, 'store'])
Route::get('/tree', [OrgUnitController::class, 'tree'])->middleware(['capability:org.tree.read', 'capability:org.unit.read']);
Route::post('/{id}/move', [OrgUnitController::class, 'move'])->whereNumber('id')->middleware(IdempotentCommand::class)->middleware('capability:org.unit.manage');
Route::get('/{id}/impact', [OrgUnitController::class, 'impact'])->whereNumber('id')->middleware('capability:org.unit.manage');
```
`DepartmentController::store()` 统一校验 `code/name/type/parent_id/status/valid_from/valid_to`；`update()` 同时接受基本信息与 `new_parent_id/effective_from`：仅 basic fields 时走受控 revise 命令（**新增** `OrgUnitService::revise()`），带 parent 时调用 `move()`；返回 `{org_unit, moved, old_org_unit_id?}`。`frontend/src/modules/people/departments/api.ts` 不再向 `/v1/org-units` POST create。
- [ ] **Step 4: 运行确认通过**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=DepartmentSingleWriterTest
cd ../frontend && pnpm vitest run tests/unit/departmentSingleWriter.spec.ts
```
Expected: PASS；唯一 writer 测试无第二个 create，`PATCH` 不再返回 422。
- [ ] **Step 5: 提交**
```bash
git add backend/routes/api.php backend/app/Modules/Organization backend/tests/Feature/Organization/DepartmentSingleWriterTest.php docs/openapi/openapi.yaml frontend/src/modules/people/departments/api.ts frontend/tests/unit/departmentSingleWriter.spec.ts
git commit -m "refactor(m05): collapse department writers"
```
### Task 2: 负责人与编制事实
**Files:** Create `backend/database/migrations/2026_11_27_000001_add_manager_to_org_units.php`、`backend/database/migrations/2026_11_27_000002_create_org_unit_headcount_versions.php`、`backend/app/Modules/Organization/Models/OrgUnitHeadcountVersion.php`、`backend/app/Modules/Organization/Services/DepartmentStaffingService.php`（Organization 内新目录）; Modify `Organization/Http/Controllers/DepartmentController.php`、`Organization/Services/OrgUnitService.php`、`frontend/src/modules/people/departments/components/DepartmentFormDrawer.vue`、`DepartmentWorkspacePage.vue`、`docs/openapi/openapi.yaml`; Test `backend/tests/Feature/Organization/DepartmentHeadcountTest.php`、`frontend/tests/unit/departmentHeadcount.spec.ts`
**Interfaces:** Consumes `DepartmentStaffingDirectory::activeHeadcount()`、People assignment 只读投影。Produces migration 两列/两张事实：`org_units.manager_employment_id`、`org_unit_headcount_versions(org_unit_id,headcount_limit,effective_from,recorded_at,recorded_by)`；`PUT /v1/departments/{id}/headcount` **新增** 或 `POST /v1/departments/{id}/headcount` **新增**（仅一个），返回新版本；`GET /v1/departments/{id}/staffing?as_of=` **新增** 返回 `headcount_limit/staffed/vacancies/overstaffed/version/effective_from`。
- [ ] **Step 1: 写失败测试**
```php
public function test_headcount_version_is_append_only_and_not_derived_from_employee_count(): void
{
    $unit = $this->orgUnit('HC-1');
    $this->postJson("/v1/departments/{$unit->id}/headcount", ['headcount_limit' => 5, 'effective_from' => '2026-11-01'], ['Idempotency-Key' => 'hc-1'])->assertCreated();
    $this->postJson("/v1/departments/{$unit->id}/headcount", ['headcount_limit' => 7, 'effective_from' => '2026-12-01'], ['Idempotency-Key' => 'hc-2'])->assertCreated();
    $this->assertDatabaseCount('org_unit_headcount_versions', 2);
    $this->getJson("/v1/departments/{$unit->id}/staffing?as_of=2026-11-15")->assertOk()
        ->assertJsonPath('data.headcount_limit', 5)->assertJsonPath('data.version', 1);
}
```
- [ ] **Step 2: 运行确认失败**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=DepartmentHeadcountTest
```
Expected: FAIL，表/路由不存在。
- [ ] **Step 3: 前向迁移与受权命令**
```php
Schema::table('org_units', function (Blueprint $table): void {
    $table->unsignedBigInteger('manager_employment_id')->nullable()->after('status')->index();
});
Schema::create('org_unit_headcount_versions', function (Blueprint $table): void {
    $table->id(); $table->unsignedBigInteger('org_unit_id')->index();
    $table->unsignedInteger('headcount_limit'); $table->date('effective_from');
    $table->dateTime('recorded_at', 6); $table->unsignedBigInteger('recorded_by')->nullable();
    $table->timestamps(); $table->unique(['org_unit_id', 'effective_from']);
});
```
`DepartmentController::storeHeadcount()` 使用 `IdempotencyRunner`，校验非负整数与生效日，先锁部门再插版本；`staffing()` 取 `effective_from <= as_of ORDER BY effective_from DESC,id DESC LIMIT 1`，在岗数仍来自 `DepartmentStaffingDirectory`，两者不互相覆盖。Drawer 增加负责人只读选择与编制/生效日字段；不允许直接写 `staffed`。
- [ ] **Step 4: 运行确认通过**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=DepartmentHeadcountTest
cd ../frontend && pnpm vitest run tests/unit/departmentHeadcount.spec.ts
```
Expected: PASS；两版本并存，旧 as-of 读旧编制，员工数不写编制表。
- [ ] **Step 5: 提交**
```bash
git add backend/database/migrations/2026_11_27_000001_add_manager_to_org_units.php backend/database/migrations/2026_11_27_000002_create_org_unit_headcount_versions.php backend/app/Modules/Organization backend/tests/Feature/Organization/DepartmentHeadcountTest.php docs/openapi/openapi.yaml frontend/src/modules/people/departments frontend/tests/unit/departmentHeadcount.spec.ts
git commit -m "feat(m05): version department headcount and manager"
```
### Task 3: 把列表、成员、编制折入部门工作区
**Files:** Modify `frontend/src/modules/people/departments/pages/DepartmentWorkspacePage.vue`、`components/DepartmentMembersPanel.vue`、`components/DepartmentTree.vue`、`frontend/src/modules/people/departments/api.ts`、`frontend/src/router/registry.ts`、`frontend/src/modules/people/catalog.ts`、`pages/index.ts`; Test `frontend/tests/unit/departmentWorkspaceTabs.spec.ts`
**Interfaces:** Consumes `fetchOrgTree()`、`fetchDepartmentPage()`、`fetchDepartmentMembers()`、staffing API。Produces 页面 query `{dept?:number,tab:'overview'|'list'|'members'|'staffing'}`；选树节点保持同一事实源，成员行链接 `/hr/employees/:id`。
- [ ] **Step 1: 写失败测试**
```ts
const page = readFileSync('src/modules/people/departments/pages/DepartmentWorkspacePage.vue', 'utf8')
expect(page).toContain("type DepartmentTab = 'overview' | 'list' | 'members' | 'staffing'")
expect(page).toContain('route.query.dept')
expect(page).toContain('DepartmentMembersPanel')
```
- [ ] **Step 2: 运行确认失败**
```bash
cd frontend && pnpm vitest run tests/unit/departmentWorkspaceTabs.spec.ts
```
Expected: FAIL，页面仍只有单视图/成员预览。
- [ ] **Step 3: 实现 role-aware tabs**
```ts
type DepartmentTab = 'overview' | 'list' | 'members' | 'staffing'
const activeTab = ref<DepartmentTab>('overview')
const selectedId = ref<number | null>(null)
function selectDepartment(id: number): void {
  selectedId.value = id
  void router.replace({ query: { ...route.query, dept: String(id), tab: activeTab.value } })
}
```
Tab 内容：`list` 用同一 `departments` 数据表，不请求第二接口；`members` 用 `fetchDepartmentMembers(id, asOf)` 并有员工详情深链；`staffing` 用 Task 2 的 `GET /v1/departments/{id}/staffing`。删除四个旧 registry 行、catalog config 与 loader。
- [ ] **Step 4: 运行确认通过**
```bash
cd frontend && pnpm vitest run tests/unit/departmentWorkspaceTabs.spec.ts tests/unit/DepartmentWorkspace.spec.ts
```
Expected: PASS；`?dept=&tab=` 刷新保持，成员可跳员工详情。
- [ ] **Step 5: 提交**
```bash
git add frontend/src/modules/people/departments frontend/src/router/registry.ts frontend/src/modules/people/catalog.ts frontend/src/modules/people/pages/index.ts frontend/tests/unit/departmentWorkspaceTabs.spec.ts
git commit -m "refactor(m05): fold department list members and staffing into workspace"
```
### Task 4: 移动向导复用影响预览与幂等确认
**Files:** Modify `frontend/src/modules/people/departments/pages/DepartmentWorkspacePage.vue`、`components/DepartmentFormDrawer.vue`、`frontend/src/modules/people/departments/api.ts`、`backend/app/Modules/Organization/Http/Controllers/OrgUnitController.php`、`Services/OrgUnitService.php`、`docs/openapi/openapi.yaml`; Test `backend/tests/Feature/Organization/DepartmentMoveImpactTest.php`、`frontend/tests/unit/departmentMove.spec.ts`
**Interfaces:** Consumes `GET /v1/org-units/{id}/impact`、`POST /v1/org-units/{id}/move`、`canUseAsParent()`。Produces impact DTO `{affected_employee_count,affected_manager_count,payroll_cost_center_count,cross_entity_warning,cycle_refused,authorization_warning}`；move 用同一幂等键返回 `{moved,old_org_unit_id,org_unit}`。
- [ ] **Step 1: 写失败测试**
```php
$this->postJson("/v1/org-units/{$unit->id}/move", ['new_parent_id' => $target->id, 'effective_from' => '2026-12-01'], ['Idempotency-Key' => 'move-1'])->assertOk();
$this->postJson("/v1/org-units/{$unit->id}/move", ['new_parent_id' => $target->id, 'effective_from' => '2026-12-01'], ['Idempotency-Key' => 'move-1'])->assertOk();
$this->assertSame(1, OrgUnitClosure::query()->where('descendant_id', $unit->id)->where('valid_from', '2026-12-01')->count());
```
- [ ] **Step 2: 运行确认失败**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=DepartmentMoveImpactTest
```
Expected: FAIL，impact 无 cycle/审批字段或第二次移动产生重复闭包。
- [ ] **Step 3: 补预览字段与幂等确认**
```php
return ApiResponse::ok([
  'affected_employee_count' => $impact->affectedEmployeeCount,
  'affected_manager_count' => $impact->managerCount,
  'payroll_cost_center_count' => $impact->payrollCostCenterCount,
  'cross_entity_warning' => $impact->crossEntityWarning,
  'cycle_refused' => $service->wouldCycle($id, $newParentId),
  'authorization_warning' => $this->authorizationNotice($principal, $id, $newParentId),
]);
```
Drawer 两步：选择目标/日期 → 预览影响 → 确认；`canUseAsParent()` 先在客户端拒绝环；确认按钮提交固定 `Idempotency-Key`，成功后关闭并刷新树/成员/编制。
- [ ] **Step 4: 运行确认通过**
```bash
cd backend && ../.tools/php/php.exe vendor/bin/phpunit --filter=DepartmentMoveImpactTest
cd ../frontend && pnpm vitest run tests/unit/departmentMove.spec.ts
```
Expected: PASS；环拒绝、跨范围警告、重复键只有一次闭包变更。
- [ ] **Step 5: 提交**
```bash
git add backend/app/Modules/Organization backend/tests/Feature/Organization/DepartmentMoveImpactTest.php docs/openapi/openapi.yaml frontend/src/modules/people/departments frontend/tests/unit/departmentMove.spec.ts
git commit -m "feat(m05): close department move preview and idempotency"
```
### Task 5: 删除四个旧入口并跑门禁
**Files:** Delete `frontend/src/modules/people/pages/Department{List,Members,Move,Staffing}Page.vue`; Modify `frontend/src/modules/people/pages/index.ts`、`catalog.ts`、`frontend/src/router/registry.ts`、`backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`、`frontend/tests/unit/peoplePageModules.spec.ts`; Test `frontend/tests/unit/departmentSubroutesRemoved.spec.ts`、`frontend/tests/e2e/real/platform-workspaces.spec.ts`
**Interfaces:** Consumes Task 1–4 的单一 writer、编制版本、Tabs、移动向导。Produces 只保留 `hr/departments/index` loader；旧路径/动作 0 残留。
- [ ] **Step 1: 写失败测试**
```ts
const r = readFileSync('src/router/registry.ts', 'utf8')
for (const s of ['list', 'members', 'move', 'staffing']) {
  expect(r).not.toContain(`path: '/hr/departments/${s}'`)
}
expect(r).toContain("path: '/hr/departments'")
```
- [ ] **Step 2: 运行确认失败**
```bash
cd frontend && pnpm vitest run tests/unit/departmentSubroutesRemoved.spec.ts
```
Expected: FAIL，旧路径仍注册。
- [ ] **Step 3: 删除页面与注册**
```bash
cd frontend/src/modules/people/pages && git rm DepartmentListPage.vue DepartmentMembersPage.vue DepartmentMovePage.vue DepartmentStaffingPage.vue
```
删除 catalog 四个 config、barrel 四个 loader、registry 四行和 PermissionResourceCatalog 四个 page entries；`peoplePageModules.spec.ts` loader 数从 35 改为 31（本模块只减 4）。
- [ ] **Step 4: 残留扫描、单测、E2E、门禁**
```bash
cd frontend
rg -n "DepartmentListPage|DepartmentMembersPage|DepartmentMovePage|DepartmentStaffingPage|/hr/departments/(list|members|move|staffing)" src tests
pnpm vitest run tests/unit/departmentSubroutesRemoved.spec.ts tests/unit/DepartmentWorkspace.spec.ts tests/unit/orgMove.spec.ts
pnpm playwright test --config=playwright.real.config.ts tests/e2e/real/platform-workspaces.spec.ts
cd .. && pnpm gate:all
```
Expected: `rg` 0 行；Vitest PASS；E2E/gate 实际 PASS 才记录，环境缺失写 `NOT RUN`。
- [ ] **Step 5: 运行确认通过**
```bash
cd frontend && pnpm vitest run tests/unit/departmentSubroutesRemoved.spec.ts tests/unit/DepartmentWorkspace.spec.ts
```
Expected: PASS，旧入口 0 残留。
- [ ] **Step 6: 提交**
```bash
git add -A frontend/src/modules/people frontend/src/router frontend/tests backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php docs
git commit -m "refactor(m05): fold department routes into workspace"
```
## 3. 权限负测
| 场景 | 请求 | 预期 |
|---|---|---|
| 未登录/无 `org.unit.read` 读树或列表 | `GET /v1/org-units/tree`、`GET /v1/departments` | 401/403 |
| 跨组织读部门（含越权组织 id） | `GET /v1/departments?org_unit_id=999` | 空集或 403，不泄露存在性 |
| 无 manage 创建/编辑/移动/改编制/移除成员 | `POST/PATCH /v1/departments`、`POST /v1/org-units/1/move`、`PUT .../headcount`、`DELETE .../members/1` | 403，org_units/闭包/版本/assignment 均不变 |
| 旧 writer 创建 | `POST /v1/org-units` | 404/405，不新增 |
| 移动到后代或无效生效日 | `POST .../move` | 409/422 `ORG_CYCLE`/校验失败，不写闭包 |
| 跨范围负责人 | `PATCH /v1/departments/1` | 422/403 |
## 4. 退出检查
- [ ] 四个旧部门路由/页面/catalog/barrel/registry 0 残留；只保留 `/hr/departments`；唯一 create writer 为 `POST /v1/departments`，`PATCH /v1/departments/{id}` 同时保存基本信息与移动，旧 `/v1/org-units` create 已删除。
- [ ] 部门列表、成员、编制、移动由同一选中部门驱动，`?dept=&tab=` 可恢复；负责人/编制版本有前向迁移、审计和 as-of 读取，在岗数不写编制表；新 API/迁移已登记全部契约与测试。
- [ ] `pnpm gate:all` 实际 PASS；E2E 截图/trace 已归档；未执行项 `NOT RUN`。
