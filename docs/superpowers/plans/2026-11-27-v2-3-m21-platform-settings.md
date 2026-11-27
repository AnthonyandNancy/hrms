# FactoryHR V2.3 M21｜公司基础设置与审计运维 — 详细实施计划
> **状态：Proposed — 待用户确认。** 本计划不得在规格批准前执行。
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.
**Goal:** 保留 `/system/audit`，把旧 `/system/settings` 原位替换为唯一设置工作区（公司、日历、字典、参数、文件五个 Tab），并将 `/system/calendars` 折叠进日历 Tab；补齐单公司资料读/写 API 与审计筛选/导出，不伪造备份状态。
**Architecture:** Platform 独占字典、参数、功能开关、文件元数据、审计读取与健康检查；Organization 独占 `company_profile` 单例；Time 独占公司日历发布。设置页只是统一入口，不把公司资料写进参数表，也不复制日历事实。审计页接现有公开 `GET /v1/health/ready`，并新增 Platform 审计查询/导出 API（`GET /v1/audit/events`、`GET /v1/audit/events/export`）承担服务端筛选；不允许前端把 `auth/events` 当前页过滤伪装成服务端筛选。
**Tech Stack:** Laravel 13 / PHP 8.3 / MySQL 8.4 / Vue 3.5 / TypeScript / Element Plus / PHPUnit / Vitest / Playwright / OpenAPI 3.1。
## Global Constraints
- 规范优先级：用户裁决 > 本计划 > V2.3 M21 模块规格 > V2.1 设计 > 历史文档。
- `/system/calendars` 不保留 redirect；registry、barrel、catalog、Vue 文件与旧测试同时删除。
- 旧 `/system/settings` 是同路径原位替换：不得注册两个 `/system/settings`，不得新增重复组件。
- 单公司单实例：`company_profile` 只有一条；禁止 `tenant_id`、`legal_entity_id`、公司选择器、多租户 SSO 假设。
- 写命令使用 `CommandRunner`/`IdempotencyRunner` + `Idempotency-Key` + `AuditRecorder`；金额整数分、时区 IANA、业务日期按公司时区/日历。
- 备份状态没有既有 API/模型：不得显示虚构“备份成功”；健康面板只使用真实 `/v1/health/ready`。
- 未执行测试、E2E、迁移演练写 `NOT RUN`，不得写“通过”。
## 1. 模块事实
### 1.1 KEEP 与替换页面
| 页面 | 路由 | 当前文件 | 目标 |
|---|---|---|---|
| 系统设置 | `/system/settings` | `frontend/src/modules/platform/pages/SystemSettingsPage.vue` | 原位替换为五 Tab：公司、日历、字典、参数、文件 |
| 审计与运维 | `/system/audit` | `frontend/src/modules/platform/pages/SystemAuditPage.vue` | 认证事件服务端筛选/导出 + 真实健康面板 + 备份缺口说明 |
| 页面注册 | 上述两条 | `frontend/src/router/registry.ts:287-290` | 仅 `system-settings`、`system-audit`；`system-calendars` 删除 |
### 1.2 FOLD 删除与能力目的地
| # | 原路由 | 删除文件/注册 | 能力目的地 |
|---:|---|---|---|
| 152 | `/system/calendars` | `frontend/src/modules/platform/pages/SystemCalendarsPage.vue`; `system-calendars` | `/system/settings?tab=calendar`；`GET /v1/time/calendars`、`POST /v1/system/calendars/publish`；`time.calendar.read/manage` |
| 153 | `/system/settings` | 旧 `SystemSettingsPage.vue` 内容 | **同路由替换**为五 Tab 组件；旧的 feature flags 归“参数”Tab，dictionary/parameters 保留真实 API；不含公司资料旧壳 |
### 1.3 当前实现与缺陷
1. `frontend/src/modules/platform/pages/SystemSettingsPage.vue` 目前实现功能开关、字典、参数以及“附件入口”链接；没有公司资料、日历 Tab、文件设置分区。`frontend/src/modules/platform/pages/SystemCalendarsPage.vue` 是独立完整页面。
2. `backend/app/Modules/Organization/Models/CompanyProfile.php` 与 `backend/app/Modules/Organization/Services/EloquentCompanyProfileReader.php` 已存在，字段 `name/country_code/timezone/currency_code/calendar_policy_json`；**没有 company profile HTTP 控制器/OpenAPI 路径**。`GET /v1/system/company-profile`、`PATCH /v1/system/company-profile` 属于 **NEW**，必须新增控制器、服务、OpenAPI、capability 与测试。
3. `backend/app/Modules/Platform/Http/Controllers/DictionaryAdminController.php` 已提供字典与参数真实读写；`SystemSettingsPage.vue` 已调用 `listDictionaries/updateDictionaryEntry/listSystemParameters/updateSystemParameter`。这些能力存在，不重写。
4. `/v1/system/calendars/publish` 由 `backend/routes/api.php:160` 注册，`time.calendar.read` 读取 `GET /v1/time/calendars`；日历发布是真实写命令。`SystemCalendarsPage.vue` 可整体迁入设置 Tab，不新增日历 API。
5. `frontend/src/modules/platform/pages/SystemAuditPage.vue` 仅列 `GET /v1/auth/events`；`frontend/src/modules/platform/api/audit.ts` 的注释明确该接口只接受 `page/page_size`，没有操作人/时间/结果/对象筛选，也没有审计导出。页面目前客户端过滤当前页，不能声称服务端筛选。
6. `backend/app/Modules/Platform/Services/HealthService.php` 与 `/v1/health/live`、`/v1/health/ready` 已存在；备份能力 `system.backup.manage` 只在能力目录中声明，无路由/模型/服务，前端不得显示假状态。
7. `frontend/src/modules/platform/pages/SystemSettingsPage.vue` 的“文件”仅链接到员工附件页 `/hr/employees/:id/files`；现有文件 API 是员工级 `GET/POST /v1/employments/{employment_id}/document-files`，没有全局文件列表或容量。文件 Tab 只能展示真实员工文件入口与已知限制，不发明“文件容量设置”API。
8. 页面数断言 `frontend/src/router/registry.ts:268`、`frontend/tests/unit/routeRegistry.spec.ts`、`scripts/audit_page_components_lib.mjs` 固定 155；本轮删 1（`system-calendars`）不新增（`system/settings` 原位替换），需同步 154。
## 2. 目标契约
### 2.1 页面结构
```text
/system/settings
├── 公司：名称、国家代码、时区、币种；只读当前值 + 保存（NEW company profile API）
├── 日历：年份、时区、工作日、标准日分钟、节假日、发布新版本
├── 字典：类型筛选、条目列表、编辑 label/active
├── 参数：键筛选、值/敏感标记；功能开关保留在参数下的只读/受控区
└── 文件：员工编号选择 -> 员工附件页真实入口；无全局容量假数据
/system/audit
├── 认证事件：分页、操作人/时间/结果（仅当服务端 API 支持）
├── 明细：脱敏 metadata、request_id
├── 健康：DB/cache/storage 真实 ready 状态（公开只读探针）
└── 备份：仅显示“未提供 API/由外部运维承担”，不得伪造成功
```
六态：`loading | empty | unconfigured | forbidden | error | success`；`unconfigured` 用于公司资料未初始化、日历空版本、文件未选员工、健康探针不可用。
### 2.2 DTO / API
| 操作 | 状态 | Capability | 契约 |
|---|---|---|---|
| `GET/PATCH /v1/system/company-profile` | **NEW** | `system.settings.manage` | 仅 `name/country_code/timezone/currency_code/calendar_policy`；IANA/ISO 校验；`CommandRunner` + 幂等 + 审计；无租户/法人字段 |
| `GET /v1/time/calendars?year=` | 现有 | `time.calendar.read` | 单公司日历快照 |
| `POST /v1/system/calendars/publish` | 现有 | `time.calendar.manage` | 整体发布新版本；审计 `time.calendar.published` |
| `GET/POST/PATCH /v1/system/dictionaries`、`GET/PUT /parameters` | 现有 | `system.dictionary.manage`/`system.parameter.manage` | type+code 唯一；值必须 `{value:...}`；敏感参数加密遮罩 |
| `GET /v1/platform/features`、`PUT /v1/platform/features/{flag}` | 现有 | `platform.feature.manage` | 功能开关；保留现有页面行为 |
| `GET /v1/employments/{employment_id}/document-files`、`POST ...` | 现有 | 员工文件权限 | 文件 Tab 只跳转/调用员工级真实 API |
| `GET /v1/auth/events` | 现有 | `iam.audit.read` | 仅 page/page_size；不得声称服务端筛选 |
| `GET /v1/audit/events` | **NEW** | `iam.audit.read` | `actor/action/result/from/to/page` 服务端过滤 `auth_events`；与 `GET /v1/auth/events` 同字段结构 |
| `GET /v1/audit/events/export` | **NEW** | `iam.audit.read`（其 actions 已含 export） | 白名单字段导出；写敏感访问审计与 `iam.audit.exported` |
| `GET /v1/health/ready` | 现有，公开 | public | `status/version/checks/time`；503 表示未就绪 |
### 2.3 状态与主体
```text
公司资料：UNSET -> ACTIVE；单条记录，更新保留审计前后值
日历：无版本 -> v1 -> v2（整体替换快照；历史只留审计）
字典：ACTIVE <-> INACTIVE，type+code 唯一；参数：更新写审计，敏感值只返回 masked
审计：append-only，按操作人/对象/动作/结果/时间索引
```
`actor_user_id` 来自 session；公司资料由 `Organization` 服务写，Platform 只读投影供设置页；日历由 Time 写，其他模块按共享 company profile/日历读。所有写动作不得接受客户端 `tenant_id/legal_entity_id`。
### 2.4 权限与字段
- `/system/settings` 进入 `system.settings.manage`；公司/参数写入同一 capability；字典 `system.dictionary.manage`、参数 `system.parameter.manage`、功能开关 `platform.feature.manage`、日历 `time.calendar.manage` 分别显式控制。
- `/system/audit` 进入 `iam.audit.read`；现有能力目录的 `iam.audit.read` 已声明 `read/export`，本计划不再新增 `iam.audit.export`，导出仍由 `iam.audit.read` + 字段白名单 + 敏感访问审计保护。
- 字段：公司名 P1；国家/时区/币种 P1；字典/参数 P2；敏感参数只回 `masked`；审计 metadata 只展示脱敏后的前后差异，不展示密码/TOTP/token/完整身份证。
- 错误中文映射：`COMPANY_PROFILE_INVALID`、`COMPANY_PROFILE_CONFLICT`（版本冲突）、`CALENDAR_VERSION_CONFLICT`、`EXPORT_FIELD_NOT_ALLOWED`、`BACKUP_STATUS_UNAVAILABLE`。
## 3. 任务
### Task 1: 单公司资料读写 API 与设置公司 Tab
**Files:** Create `backend/app/Modules/Organization/Http/Controllers/CompanyProfileController.php`、`backend/app/Modules/Organization/Services/CompanyProfileWriter.php`、`backend/app/Modules/Organization/Contracts/CompanyProfileWriter.php`、`backend/tests/Feature/Organization/CompanyProfileApiTest.php`、`frontend/src/modules/platform/components/SettingsCompanyTab.vue`、`frontend/tests/unit/SettingsCompanyTab.spec.ts`；Modify `backend/routes/api.php`、`docs/openapi/openapi.yaml`、`docs/generated/action-input.json`、`backend/app/Modules/Organization/Providers/OrganizationServiceProvider.php`、`frontend/src/modules/platform/api/settings.ts`、`frontend/src/modules/platform/pages/SystemSettingsPage.vue`。
**Interfaces:**
- Consumes: `CompanyProfile` 单例、`CompanyProfileReader`、`Clock`、`CommandRunner`、`AuditRecorder`。
- Produces: `GET/PATCH /v1/system/company-profile`；`CompanyProfileWriter::update(array $input, int $actorUserId): array{name,country_code,timezone,currency_code,calendar_policy}`；`updateCompanyProfile()` 客户端；`settings.company-profile.save` 动作挂 `system-settings`。
- [ ] **Step 1: 写失败测试**
```php
public function test_company_profile_is_single_company_read_and_update_with_audit(): void
{
    $this->actAsHttp($this->settingsAdmin());
    $this->getJson('/v1/system/company-profile')->assertOk()
        ->assertJsonPath('data.name', 'Synthetic Company')
        ->assertJsonMissingPath('data.tenant_id')
        ->assertJsonMissingPath('data.legal_entity_id');
    $this->patchJson('/v1/system/company-profile', ['name' => '合成制造有限公司', 'country_code' => 'CN', 'timezone' => 'Asia/Shanghai', 'currency_code' => 'CNY'], ['Idempotency-Key' => 'cp-1'])
        ->assertOk()->assertJsonPath('data.name', '合成制造有限公司');
    $this->assertSame(1, DB::table('company_profile')->count());
    $this->assertDatabaseHas('audit_events', ['action' => 'system.company_profile.updated']);
    $this->patchJson('/v1/system/company-profile', ['timezone' => 'Mars/Olympus'], ['Idempotency-Key' => 'cp-2'])->assertUnprocessable();
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter CompanyProfileApiTest`
Expected: FAIL；路由/控制器/Writer 不存在。
- [ ] **Step 3: 实现控制器、Writer、OpenAPI**
```php
public function update(Request $request): JsonResponse
{
    $data = $request->validate([
        'name' => ['required', 'string', 'max:160'],
        'country_code' => ['required', 'string', 'size:2'],
        'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
        'currency_code' => ['required', 'string', 'size:3'],
    ]);
    return ApiResponse::ok($this->writer->update($data, $this->principals->resolve()->userId()));
}
```
Writer 在事务内锁 `company_profile` 第 1 行，读取 before，更新，写 `system.company_profile.updated` 审计；`calendar_policy` 不在本任务写入，保留现有值。路由放在 IAM/Platform admin group 相邻真实管理路由处，`capability:system.settings.manage,COMPANY` + `csrf.header`；OpenAPI 新增 200/401/403/422/500 与 `x-capability`。前端 `SettingsCompanyTab` 只提交四字段，成功刷新；无能力 403 显示中文；每次打开重置为服务端最新值。
- [ ] **Step 4: 跑测试并提交**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'CompanyProfileApiTest|SingleCompanyPreflightTest|SingleCompanyMigrationTest'`
Expected: PASS。
Run: `cd frontend && pnpm vitest run tests/unit/SettingsCompanyTab.spec.ts`
Expected: PASS。
```bash
git add backend/app/Modules/Organization backend/routes/api.php backend/tests/Feature/Organization docs/openapi/openapi.yaml docs/generated/action-input.json frontend/src/modules/platform
git commit -m "feat(platform): add single-company profile settings API and tab"
```
### Task 2: 五 Tab 设置工作区与日历折叠
**Files:** Modify `frontend/src/modules/platform/pages/SystemSettingsPage.vue`、`frontend/src/modules/platform/api/settings.ts`、`frontend/src/router/registry.ts`、`frontend/tests/unit/SettingsWorkspace.spec.ts`；Create `frontend/src/modules/platform/components/SettingsCalendarTab.vue`、`SettingsDictionaryTab.vue`、`SettingsParameterTab.vue`、`SettingsFileTab.vue`；Delete `frontend/src/modules/platform/pages/SystemCalendarsPage.vue`；Modify `frontend/src/modules/platform/pages/index.ts`、`frontend/src/modules/platform/catalog.ts`。
**Interfaces:**
- Consumes: Task 1 公司与现有字典/参数/功能开关/日历 API、员工文件入口；`PageShell/ElTabs`。
- Produces: `/system/settings?tab=company|calendar|dictionary|parameter|file`；test ids `settings-tabs`、`settings-company`、`settings-calendar`、`settings-dictionary`、`settings-parameter`、`settings-file`；registry 只保留 `system-settings`。
- [ ] **Step 1: 写失败测试**
```ts
it('renders one settings workspace with five real tabs and no calendar route', async () => {
  const wrapper = await mountSettings(['system.settings.manage', 'time.calendar.read', 'system.dictionary.manage', 'system.parameter.manage'])
  expect(wrapper.get('[data-testid="settings-tabs"]').text()).toContain('公司')
  expect(wrapper.get('[data-testid="settings-tabs"]').text()).toContain('日历')
  expect(wrapper.get('[data-testid="settings-tabs"]').text()).toContain('字典')
  expect(wrapper.get('[data-testid="settings-tabs"]').text()).toContain('参数')
  expect(wrapper.get('[data-testid="settings-tabs"]').text()).toContain('文件')
  expect(PAGE_REGISTRY.some((page) => page.path === '/system/calendars')).toBe(false)
})
it('publishes a calendar version from the calendar tab', async () => {
  const wrapper = await mountSettings(['system.settings.manage', 'time.calendar.read', 'time.calendar.manage'], '/system/settings?tab=calendar')
  await wrapper.get('[data-testid="settings-calendar-year"]').setValue(2027)
  await wrapper.get('[data-testid="settings-calendar-publish"]').trigger('click')
  expect(resource.post).toHaveBeenCalledWith('/v1/system/calendars/publish', expect.objectContaining({ timezone: expect.any(String) }), expect.objectContaining({ idempotencyKey: expect.any(String) }))
})
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd frontend && pnpm vitest run tests/unit/SettingsWorkspace.spec.ts`
Expected: FAIL；当前无五 Tab，`/system/calendars` 仍注册。
- [ ] **Step 3: 实现五 Tab 与移除日历页**
`SystemSettingsPage.vue` 原位替换为容器；公司 Tab 使用 Task 1；日历 Tab 迁入 `SystemCalendarsPage.vue` 的完整读取/发布/确认逻辑，保留 `getCompanyCalendar`/`publishCompanyCalendar` 与 `time.calendar.read/manage`；字典/参数 Tab 迁入现有真实读写与 feature flags；文件 Tab 保留员工编号输入与 `/hr/employees/:id/files` 链接，并声明“文件容量/全局访问策略尚无 API”，不展示假开关。删除 `SystemCalendarsPage.vue`、`system/system-calendars` barrel key、platform catalog `calendars` 与 registry `system-calendars`；`registry` 的 `system-settings` 原位保留 `page_code` 并更新 `requiredApis`。
- [ ] **Step 4: 更新计数与提交**
`routeRegistry.spec.ts` 与 `scripts/audit_page_components_lib.mjs` 从 155 改 154；`talent` 不受影响；`SettingsWorkspace.spec.ts` 的旧日历页挂载用例改为日历 Tab；删除 `frontend/tests/e2e/real` 中对 `/system/calendars` 的访问（Task 5 同步改）。Run: `cd frontend && pnpm vitest run tests/unit/SettingsWorkspace.spec.ts tests/unit/routeRegistry.spec.ts`
Expected: PASS。
```bash
git add -A frontend/src/modules/platform frontend/src/router/registry.ts frontend/tests/unit scripts/audit_page_components_lib.mjs
git commit -m "feat(platform): consolidate settings and fold calendars into one workspace"
```
### Task 3: 审计服务端筛选、导出与健康面板
**Files:** Create `backend/app/Modules/Platform/Http/Controllers/AuditQueryController.php`、`backend/app/Modules/Platform/Services/AuditQueryService.php`、`backend/tests/Feature/Platform/AuditQueryTest.php`、`frontend/src/modules/platform/components/AuditHealthPanel.vue`、`frontend/tests/unit/AuditQuery.spec.ts`；Modify `backend/routes/api.php`、`docs/openapi/openapi.yaml`、`docs/generated/action-input.json`、`frontend/src/modules/platform/api/audit.ts`、`frontend/src/modules/platform/pages/SystemAuditPage.vue`。
**Interfaces:**
- Consumes: `auth_events`（append-only）、`AccessReviewController`、`HealthService`/`/v1/health/ready`；`iam.audit.read`。
- Produces: `GET /v1/audit/events?actor&action&result&from&to&page&page_size` 返回与 `auth/events` 相同字段并服务端过滤；`GET /v1/audit/events/export` 需 `iam.audit.read` + 白名单 + 敏感访问审计；健康面板读取 `/v1/health/ready` 的 `status/version/checks`；备份区只显示“外部运维承担/暂无 API”。
- [ ] **Step 1: 写失败测试**
```php
public function test_audit_query_filters_server_side_and_export_is_whitelisted(): void
{
    $this->actAsHttp($this->settingsAdmin());
    $this->getJson('/v1/audit/events?actor=15&result=FAILURE&from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson('/v1/audit/events/export?fields=event_type,result,occurred_at')->assertOk()->assertJsonStructure(['data' => ['fields', 'rows']]);
    $this->getJson('/v1/audit/events/export?fields=password')->assertUnprocessable()->assertJsonPath('data.reason', 'EXPORT_FIELD_NOT_ALLOWED');
    $this->assertDatabaseHas('audit_events', ['action' => 'iam.audit.exported']);
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter AuditQueryTest`
Expected: FAIL；审计筛选/导出路由不存在（当前只有 `auth/events`）。
- [ ] **Step 3: 实现审计读模型与健康面板**
`AuditQueryService` 从 append-only `auth_events` 按 actor/action/result/from/to 服务端过滤并分页；OpenAPI 与页面标题明确当前审计范围为“登录与授权安全事件”，不混入业务审计表。`AuditQueryController` 的两个 operation 都要求 `iam.audit.read`（现有能力声明含 export 动作），导出额外校验字段白名单；导出字段白名单 `event_type,result,user_id,request_id,occurred_at`，禁止密码/TOTP/token/完整身份证；导出写 SensitiveAccessAuditor + `iam.audit.exported` 审计。`SystemAuditPage` 使用真实服务端筛选与分页，删除客户端过滤逻辑。`AuditHealthPanel` 调用 `/v1/health/ready` 显示 status/version/checks，503 显示未就绪；备份区固定显示“备份恢复由外部运维平台承担，本系统未提供状态 API”，不得出现假“最近成功时间”。
- [ ] **Step 4: 跑测试并提交**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'AuditQueryTest|NotificationAuditWorkspaceTest|AccessReviewEndpointTest'`
Expected: PASS。
Run: `cd frontend && pnpm vitest run tests/unit/AuditQuery.spec.ts tests/unit/NotificationAuditWorkspace.spec.ts`
Expected: PASS。
```bash
git add backend/app/Modules/Platform backend/routes/api.php backend/tests/Feature/Platform docs/openapi/openapi.yaml docs/generated/action-input.json frontend/src/modules/platform frontend/tests/unit
git commit -m "feat(platform): add server-side audit filtering, export and health panel"
```
### Task 4: 设置/审计 OpenAPI、动作清单与单公司门禁
**Files:** Modify `docs/openapi/openapi.yaml`、`docs/generated/action-input.json`、`docs/generated/page-actions.json`、`frontend/src/generated/pageActions.ts`、`backend/tests/Authorization/endpoint_matrix.php`、`backend/tests/Contract/PagePermissionCoverageTest.php`、`backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`、`backend/app/Modules/IAM/Authorization/RolePageAssignmentCatalog.php`、`frontend/src/router/registry.ts`、`frontend/tests/unit/routeRegistry.spec.ts`；Gate (read-only): `scripts/audit_single_company.mjs`。
**Interfaces:**
- Consumes: Task 1-3 的 NEW API 与真实动作。
- Produces: `system-settings` 的 `requiredApis` 覆盖 company-profile GET/PATCH、calendar GET/POST、dictionary、parameter、features、员工文件；`system-audit` 覆盖 auth events 或 audit events/export、health；`company-profile` 两个新 operation 有 `x-capability: system.settings.manage`；`system.backup.manage` 仍无路由，不新增假 action。
- [ ] **Step 1: 写失败契约测试**
```php
public function test_settings_page_operations_are_declared_and_single_company(): void
{
    $operationIds = $this->openApiOperationIds();
    foreach (['getCompanyProfile', 'updateCompanyProfile', 'listDictionaries', 'updateSystemParameter', 'publishCompanyCalendar', 'listAuthEvents'] as $id) {
        $this->assertContains($id, $operationIds);
    }
    $this->assertStringNotContainsString('tenant_id', file_get_contents(base_path('../docs/openapi/openapi.yaml')));
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter PagePermissionCoverageTest`
Expected: FAIL；新 operation/动作/页面 requiredApis 未同步。
- [ ] **Step 3: 同步契约与目录**
`PermissionResourceCatalog` 的 `system-settings` 更新 `required_apis` 和 actions（`system.settings.manage`、`system.dictionary.manage`、`system.parameter.manage`、`platform.feature.manage`、`time.calendar.read/manage`）；`system-audit` 更新 `required_apis`（`GET /v1/audit/events`、`GET /v1/audit/events/export`、`GET /v1/health/ready`）；`system-calendars` 条目删除。计数必须按 M19 之后的基线计算：M19 把后端 153 变为 150、前端 155 变为 152；M21 再删 `system-calendars`，最终后端 149、前端 151。`PagePermissionCoverageTest` 等固定值按 M19 中间基线 150/152 更新，再由本模块改为 149/151；不得各自从 153/155 重算。`action-input.json` 新增 `system.settings.company-profile.save`、`system.settings.calendar.publish`、`system.settings.dictionary.update`、`system.settings.parameter.update`、`system.settings.feature.update`、`system.audit.export`（capability 仍 `iam.audit.read`），并运行 `pnpm actions:generate`。`endpoint_matrix.php` 为 `GET/PATCH /v1/system/company-profile`、`GET /v1/audit/events`、`GET /v1/audit/events/export` 补负测条目。
- [ ] **Step 4: 跑门禁并提交**
Run: `pnpm actions:generate && pnpm actions:check && pnpm page-components:check && pnpm single-company:check`
Expected: exit 0。
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'PagePermissionCoverageTest|CompanyProfileApiTest|AuditQueryTest'`
Expected: PASS。
```bash
git add docs/openapi/openapi.yaml docs/generated frontend/src/generated backend/tests backend/app/Modules/IAM frontend/src/router/registry.ts
git commit -m "chore(platform): align settings and audit contracts with real APIs"
```
### Task 5: 真实栈 E2E 与证据
**Files:** Modify `frontend/tests/e2e/real/settings-workspace.spec.ts`、`platform-workspaces.spec.ts`、`notification-audit.spec.ts`；Create `docs/implementation/M21/04-evidence.md`、`docs/implementation/M21/evidence/`。
**Interfaces:** Consumes `demo.admin`/`demo.hr`/`demo.employee`；Produces 五 Tab、日历发布、公司资料保存、审计拒绝、健康与备份缺口语义证据。
- [ ] **Step 1: 写失败 E2E**
```ts
test('settings workspace owns company, calendar, dictionary, parameter and file tabs', async ({ page }) => {
  await login(page, ADMIN_USER)
  await page.goto('/system/settings?tab=company', { waitUntil: 'load' })
  await expect(page.getByTestId('settings-tabs')).toBeVisible()
  await page.getByTestId('settings-company-name').fill('合成制造有限公司')
  await page.getByTestId('settings-company-save').click()
  await expect(page.getByTestId('settings-company-success')).toBeVisible()
  await page.goto('/system/settings?tab=calendar', { waitUntil: 'load' })
  await expect(page.getByTestId('settings-calendar')).toBeVisible()
  await page.goto('/system/calendars', { waitUntil: 'load' })
  await expect(page).toHaveURL(/not-found|forbidden/)
})
test('audit surfaces real health and explicitly discloses the backup gap', async ({ page }) => {
  await login(page, ADMIN_USER)
  await page.goto('/system/audit', { waitUntil: 'load' })
  await expect(page.getByTestId('audit-health-panel')).toBeVisible()
  await expect(page.getByTestId('audit-backup-unavailable')).toContainText('未提供')
})
```
覆盖：无 `system.settings.manage` 保存公司资料 403；无 `time.calendar.manage` 日历发布按钮隐藏且直接 post 403；无 `iam.audit.read` 审计页 forbidden；`/system/calendars` 404 且无 redirect；文件 Tab 不展示虚构容量；健康探针 503 显示未就绪。计划阶段 `NOT RUN`。
- [ ] **Step 2: 运行真实栈**
```bash
cd backend && php artisan migrate:fresh --seed && php artisan serve --port=8000
cd frontend && HRMS_DEMO_PASSWORD="$HRMS_DEMO_PASSWORD" pnpm exec playwright test tests/e2e/real/settings-workspace.spec.ts tests/e2e/real/platform-workspaces.spec.ts tests/e2e/real/notification-audit.spec.ts --config=playwright.real.config.ts
```
Expected: `NOT RUN`（本计划未执行）；执行后 exit 0，否则 FAILED 并阻断。
- [ ] **Step 3: 记录证据并提交**
`04-evidence.md` 写命令、退出码、日志、截图、`company_profile/dictionaries/params/calendar/sys_feature_flags/auth_events` 前后快照；备份状态写“外部运维/无 API，NOT RUN”；未执行项写 `NOT RUN`。
```bash
git add frontend/tests/e2e/real docs/implementation/M21
git commit -m "test(platform): add consolidated settings and truthful audit evidence"
```
## 4. 权限负向测试
| 动作 | 未登录 | 无能力 | 跨范围 | 字段/导出 | 状态冲突 | 幂等 |
|---|---|---|---|---|---|---|
| 公司资料读取 | 401 | 403 | — | — | — | 只读 |
| 公司资料更新 | 401 | 403 | — | 时区/国家/币种 422 | 单例冲突 409 | 同键 replay |
| 日历读取/发布 | 401 | 403 | 非公司组织范围 403 | 工作日/节假日 P1 | 版本冲突 409 | 同键 replay |
| 字典创建/更新 | 401 | 403 | — | label P1 | type+code 重复 409 | 同键 replay |
| 参数更新 | 401 | 403 | scope 越界 403 | 敏感值 masked；shape 422 | 同键 payload 冲突 409 | 同键 replay |
| 功能开关 | 401 | 403 | — | 原因 P1 | 未知 flag 404 | 同键 replay |
| 文件 Tab | 401 | 403 | 员工越界 404 | 文件名 P2 | 无 API 显示 unconfigured | 无写 |
| 审计读取/筛选/导出 | 401 | 403 | 范围外 404 | metadata 脱敏；导出白名单 422 | — | 只读/导出幂等 |
| 备份状态 | 401 | 403 | — | — | 无 API：显式未提供 | 不伪造 |
## 5. 退出检查
- [ ] `/system/settings` 是唯一设置入口，原位替换为五 Tab：公司、日历、字典、参数、文件；`/system/calendars` 404 且无 redirect。
- [ ] `/system/calendars` 在 registry、barrel、catalog、Vue、动作、cluster membership、旧测试、导航无残留；计数 155→154。
- [ ] `GET/PATCH /v1/system/company-profile` 作为 **NEW** API 已接 OpenAPI/capability/动作/审计/测试；`company_profile` 仍单例且无租户/法人字段。
- [ ] 日期/字典/参数/功能开关均调用真实端点，未重写既有命令；文件 Tab 只使用员工级 API 并标注无全局容量 API。
- [ ] 审计筛选/导出来自 `GET /v1/audit/events`、`GET /v1/audit/events/export`；健康面板只读 `/v1/health/ready`，备份区明确“无 API/外部运维”，不伪造状态。
- [ ] 审计权限、导出白名单、敏感字段脱敏与 `iam.audit.read` 负测通过，普通业务界面不显示技术码/堆栈/API 路径。
- [ ] 真实 E2E 已执行附日志/截图；未执行写 `NOT RUN`，模块 `BLOCKED`。
- [ ] `pnpm gate:all` 退出码 0；否则不得进入 D1 合并。
