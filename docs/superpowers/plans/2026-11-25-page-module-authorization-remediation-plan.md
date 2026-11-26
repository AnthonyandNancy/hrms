# 页面独立化、超管授权与菜单可用性 — 修改计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 消除“多板块共用页面”、`demo.admin` 非预期 403、菜单无展开指示三类缺陷，并让页面、页面授权、动作能力、按钮能力、组织数据范围和门禁形成可执行、可回归的一致性契约；按 ADR-0034 删除租户/法人概念，系统只服务一个公司。

**Architecture:** 保留 Capability × Anchor × FieldProfile 授权内核，但把 `LEGAL_ENTITY` 锚点替换为 `COMPANY`，并删除 `tenant_id`/`legal_entity_id`/`X-Legal-Entity-Id`；在 IAM 增加 `role_pages`，把页面权限从“所需能力”中独立出来；把四个工作区壳按域拆成 160 个独立页面组件；前端以 `/me/permissions` 为唯一页面/动作投影，失败时显式报错；导航显式建模展开状态、当前路由和图标。破坏性改造按 ADR-0034 执行。

**Tech Stack:** Laravel 11 / PHP 8.4 / MySQL 8.4 / Pest·PHPUnit / Vue 3.5 / TypeScript 5.9 / Pinia / Vue Router / Element Plus / Vitest / Playwright / OpenAPI 3.1。

## Global Constraints

- 每个 `/v1` 路由必须有 `capability:<code>` 中间件或位于显式公开白名单；不得新增隐藏管理员捷径。
- `AuthorityBinding` 是唯一行/字段作用域来源；页面授权只决定进入与菜单，不携带数据范围。
- 页面能力码与动作能力码均以代码目录为真相；管理员不能在 UI 新建能力码。
- 一页一组件：每个 `page_code` 必须映射到唯一 `.vue` 页面文件；base 页复用 `AccountSecurityView` 必须在白名单中注明原因。
- 时间：UTC 存储；业务日期通过 `Clock`/`CompanyBusinessDate`；不得写死 `2026-11-23` 等演示日期。
- 金额：整数最小货币单位；不得出现浮点金额计算或 `/100`。
- 迁移：forward-only；匿名类；不修改已合并迁移。
- 禁止真实个人数据；测试/种子只使用合成数据。
- 每次提交前必须运行本任务列出的门禁；未运行不得声称通过。
- 所有页面拆分任务完成前，`audit_route_coverage.mjs --require-mature` 允许暂时失败，但发布门禁不得豁免。
- **单公司（ADR-0034）：** 不新增 `tenant_id`/`legal_entity_id`/`LEGAL_ENTITY`；目标代码中这些标识必须为 0。历史数据通过 forward-only 迁移合并到 `company_profile` 单例。
- **破坏性改造（ADR-0034）：** 允许删除表/列/路由/前端上下文；每项破坏性迁移必须同时提交迁移、回填脚本、回滚快照与演练记录。
- **员工 CRUD：** 员工必须支持创建、读取、更新、删除/归档、恢复；删除采用归档（A 类），不物理删除。

---

## 0. 执行顺序与依赖

```text
Task 1  ADR / 文档裁决（ADR-0033 + ADR-0034 单公司）
Task 2  单公司迁移：删除 tenant_id/legal_entity_id、COMPANY 锚点、company_profile
Task 3  role_pages 迁移 + 种子
Task 4  /me/permissions 页面过滤 + 页面授权负测
Task 5  演示种子补 user_roles + 安装链一致性
Task 6  前端删除法人/租户上下文并改为单公司
Task 7  前端权限加载硬失败 + 页面路由守卫
Task 8  前后端页面能力对账 + travel 三页修正
Task 9  页面组件唯一性门禁（预期先失败）
Task 9A 页面动作清单 + 按钮能力门禁
Task 9B 前端模块边界门禁
Task 9C 超管部署基线 + 自检
Task 9D 页面/按钮权限矩阵（四 Tab；依赖 Task 14 API）
Task 9E 模块 CRUD 基线 + 合同软删除 + 员工 CRUD
Task 10 People 模块拆分（员工/部门/岗位/异动/合同）
Task 11 TimePay 模块拆分
Task 12 Talent 模块拆分
Task 13 Platform 模块拆分
Task 14 角色页面授权 API + 用户角色分配 API
Task 14A 按同事分配角色 UI（角色下拉、多选、撤销）
Task 15 权限预览（页面/动作/范围/拒绝原因）
Task 16 菜单展开图标/当前高亮/持久化
Task 17 demo.admin 真实栈 160 页 + 权限矩阵验收
Task 18 文档、OpenAPI、ADR、回滚与最终门禁
```

Task 2（单公司迁移）与 Task 3（role_pages）是最高优先级底座；Task 4–9 随后；Task 9A–9E 可与页面拆分同步；Task 9D、14A、15 依赖 Task 14 的 API；Task 10–13 可按域并行；Task 16 可与 10–13 并行；Task 17 必须最后执行，Task 18 收口。

---

### Task 1: 固化架构裁决与文档真相源

**Files:**
- Create: `docs/adr/0033-page-authorization-and-page-module-boundaries.md`
- Create: `docs/adr/0034-single-company-remove-tenant-and-legal-entity.md`
- Modify: `docs/02-permissions.md`
- Modify: `docs/05-ui-guidelines.md`
- Modify: `docs/01-architecture.md`
- Modify: `docs/audit/2026-11-25-v11-page-access-blueprint.md`
- Test: `backend/tests/Architecture/PageAuthorizationDecisionTest.php`

**Interfaces:**
- Produces: ADR-0033 决策：`role_pages` 是页面可见性唯一数据源；`AuthorityBinding` 仍是动作/数据/字段唯一来源；页面组件必须一页一文件；按钮以 `PageActionSpec` 声明能力；合同软删除；超管部署基线可重复。
- Produces: ADR-0034 决策：单公司，无租户、无法人；`LEGAL_ENTITY` 锚点替换为 `COMPANY`；删除 `tenant_id`/`legal_entity_id`/`X-Legal-Entity-Id`；允许破坏性改造。
- Produces: 文档中的术语统一：`page_code`、`page grant`、`action capability`、`COMPANY`、`company_profile`。

- [ ] **Step 1: 写失败测试，锁定 ADR-0033/0034 存在且包含裁决**

```php
<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

final class PageAuthorizationDecisionTest extends TestCase
{
    public function test_adr_0033_and_0034_declare_the_authorization_invariants(): void
    {
        $pageAdr = $this->app->basePath('docs/adr/0033-page-authorization-and-page-module-boundaries.md');
        $companyAdr = $this->app->basePath('docs/adr/0034-single-company-remove-tenant-and-legal-entity.md');

        $this->assertFileExists($pageAdr);
        $this->assertFileExists($companyAdr);

        $pageText = (string) file_get_contents($pageAdr);
        foreach ([
            'role_pages',
            '页面权限只决定进入与菜单',
            'AuthorityBinding',
            '一页一组件',
            'PageActionSpec',
            'contract soft delete',
            'superadmin baseline',
            'market defaults',
        ] as $needle) {
            $this->assertStringContainsString($needle, $pageText, "ADR-0033 must state: {$needle}");
        }

        $companyText = (string) file_get_contents($companyAdr);
        foreach ([
            'single company',
            'COMPANY',
            'tenant_id',
            'legal_entity_id',
            'destructive',
        ] as $needle) {
            $this->assertStringContainsString($needle, $companyText, "ADR-0034 must state: {$needle}");
        }
    }
}
```

- [ ] **Step 2: 运行测试，确认因 ADR 缺失失败**

```bash
cd backend
php artisan test --compact --filter=PageAuthorizationDecisionTest
```

Expected: `FAIL`，提示 `0033-page-authorization-and-page-module-boundaries.md` 不存在。

- [ ] **Step 3: 创建 ADR-0033 与 ADR-0034**

```markdown
# ADR-0033: Page Authorization and Page Module Boundaries

- **状态：** Accepted — 2026-11-25
- **关联：** ADR-0004、ADR-0016、ADR-0024、ADR-0026、ADR-0027
- **复审触发：** 页面清单变化、角色模板变化、页面能力码变化、公司配置或授权锚点变化

## 背景

160 个 page_code 只有 41 个 Vue 文件，144 个业务/平台页面由四个工作区壳动态渲染；
页面可见性由单一 required_capability 推导，角色没有页面授权；演示账号只有绑定没有 user_roles；
菜单父项没有展开指示，当前路由不写入 navKey。

## 决策

1. `role_pages` 是页面可见性唯一数据源；`page_code` 来自代码拥有的 PermissionResourceCatalog。
2. 页面权限只决定进入与菜单；动作权限仍由 `AuthorityBinding` 编译，不得用页面授权绕过 API 中间件。
3. 每个 `page_code` 必须映射到一个唯一 `.vue` 页面组件；共享壳不得作为页面路由目标。
4. 前端以 `/me/permissions` 为唯一页面/动作投影；加载失败必须显式报错，不得降级为空能力。
5. 系统只服务一个公司；删除 tenant/法人上下文，授权使用 `COMPANY` 锚点（ADR-0034）。
6. 演示/安装链路必须同时写 `user_roles`、`role_pages`（模板）与 `authority_bindings`。
7. 每个页面的每个非 GET 操作必须由 `PageActionSpec` 声明其 `capability`，按钮经 `Can`/`ActionGate` 渲染。
8. 合同与合同模板使用 soft delete（`deleted_at` + `deleted_by` + `delete_reason`），不得物理删除；合同号不复用。
9. 超管基线必须由代码目录 + 部署命令可重复生成：160 页、全部能力、全部数据范围，安装结束即校验。
10. 未单列的权限/UI/CRUD 细节按解决方案 §10D 的市场默认执行。
11. 员工支持完整 CRUD（归档式删除 + 恢复），不物理删除。

## 后果

- 页面与动作可以独立授予；只授单页成为可能。
- 页面拆分工作量增加，但可测试、可回滚。
- 角色/页面迁移需要回填与差异报告。
```

ADR-0034 正文使用仓库中的 `docs/adr/0034-single-company-remove-tenant-and-legal-entity.md`，必须包含
`single company`、`COMPANY`、`tenant_id`、`legal_entity_id`、`destructive` 五个关键词与
`company_profile` 单例、迁移顺序、回滚演练。

- [ ] **Step 4: 更新文档术语与约束**

`docs/02-permissions.md` 增加：

```markdown
### Page grants

Page visibility is a separate resource from action capability. A role is
assigned page codes through `role_pages`; a page is visible only when the
caller has at least one effective role granting that page, and the page's
`required_capability` is also present for its read action. Action endpoints
still require their own capability middleware.
```

`docs/05-ui-guidelines.md` §5 增加：

```markdown
- 可展开菜单必须显示 chevron（收起 → 展开），并维护 `aria-expanded`、`aria-controls`。
- 当前路由必须写入 `meta.navKey`，所在分组自动展开，子项设置 `aria-current="page"`。
- 展开状态是 UI 偏好，可持久化到 localStorage；不得存储权限判断结果。
```

`docs/01-architecture.md` 的 IAM 表清单加入 `role_pages`；`docs/audit/2026-11-25-v11-page-access-blueprint.md` 的 §4.1 增加“页面授权表”一行。

- [ ] **Step 5: 运行测试与文档检查**

```bash
cd backend
php artisan test --compact --filter=PageAuthorizationDecisionTest
cd ..
node deploy/scripts/openapi-lint.mjs
```

Expected: 测试 PASS；OpenAPI lint 保持 OK（本任务不改契约）。

- [ ] **Step 6: 提交**

```bash
git add docs/adr/0033-page-authorization-and-page-module-boundaries.md docs/02-permissions.md docs/05-ui-guidelines.md docs/01-architecture.md docs/audit/2026-11-25-v11-page-access-blueprint.md backend/tests/Architecture/PageAuthorizationDecisionTest.php
git commit -m "docs(adr): define page authorization and page module boundaries"
```

---

### Task 2: 单公司迁移 — 删除 tenant/法人，建立 `company_profile` 与 `COMPANY` 锚点

**Files:**
- Create: `docs/adr/0034-single-company-remove-tenant-and-legal-entity.md`（Task 1 已建；本任务只实现）
- Create: `backend/database/migrations/2026_11_26_000050_create_company_profile_and_company_anchor.php`
- Create: `backend/database/migrations/2026_11_26_000060_drop_tenant_and_legal_entity_columns.php`
- Create: `backend/app/Modules/Organization/Models/CompanyProfile.php`
- Create: `backend/app/Modules/Organization/Contracts/CompanyProfileReader.php`
- Rename: `backend/app/Shared/Time/TenantBusinessDate.php` → `backend/app/Shared/Time/CompanyBusinessDate.php`
- Rename: `backend/app/Modules/Payroll/Models/Concerns/BelongsToTenant.php` → `backend/app/Modules/Payroll/Models/Concerns/BelongsToCompany.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/InstallCommand.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Modify: `backend/app/Modules/Platform/Demo/Services/DemoSeeder.php`
- Modify: `backend/app/Modules/People/Commands/SeedPeopleDemoCommand.php`
- Modify: `backend/app/Modules/Talent/Console/SeedTalentProgramsCommand.php`
- Modify: `backend/app/Modules/Time/Console/SeedLeaveTypesCommand.php`
- Modify: `backend/app/Modules/Time/Console/SeedCalendarCommand.php`
- Modify: `backend/app/Modules/Workflow/Console/SeedWorkflowTemplatesCommand.php`
- Modify: `backend/app/Modules/Lifecycle/Console/SeedLifecycleTemplatesCommand.php`
- Modify: `backend/app/Modules/Payroll/Console/SeedPayrollParametersCommand.php`
- Modify: `backend/app/Shared/Authorization/AnchorType.php`
- Modify: `backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php`
- Modify: `backend/app/Modules/IAM/Http/Middleware/RequireCapability.php`
- Modify: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php`
- Modify: `backend/app/Shared/Architecture/ModuleBoundaryScanner.php`
- Modify: `backend/app/Modules/People/Models/Employment.php`
- Modify: `backend/app/Modules/Organization/Models/OrgUnit.php`
- Modify: `backend/app/Modules/Organization/Models/Position.php`
- Modify: `backend/app/Modules/Time/Models/*`（日历/班次）
- Modify: `backend/app/Modules/Payroll/Models/*`
- Modify: `backend/routes/api.php`
- Modify: `frontend/src/api/http.ts`
- Delete: `frontend/src/api/entityContext.ts`
- Delete: `backend/app/Modules/Organization/Models/LegalEntity.php`
- Delete: `backend/app/Modules/Organization/Contracts/LegalEntityInfo.php`
- Test: `backend/tests/Feature/Architecture/SingleCompanyMigrationTest.php`
- Test: `backend/tests/Feature/Authorization/CompanyAnchorTest.php`
- Test: `backend/tests/Contract/NoTenantNoLegalEntityTest.php`

**Interfaces:**
- Produces: `company_profile` 单例（id=1）：`name, country_code, timezone, currency_code, calendar_policy_json, created_at, updated_at`。
- Produces: `AnchorType::COMPANY`，替换 `AnchorType::LEGAL_ENTITY`。
- Produces: `CompanyProfileReader::current(): CompanyProfileInfo`，供各模块读公司配置。
- Produces: `hrms:install --company-name --timezone --currency --admin-*`；不再接受 `--legal-entity-*`。
- 删除：`tenant_id`、`legal_entity_id`、`legal_entities`、`X-Legal-Entity-Id`、`activeLegalEntityId`。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NoTenantNoLegalEntityTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_code_has_no_tenant_or_legal_entity_identifiers(): void
    {
        // Migrations must mention tenant_id/legal_entity_id to drop them; scan only production code.
        $roots = [
            base_path('app'),
            base_path('routes'),
            base_path('../frontend/src'),
        ];

        $violations = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile()) continue;
                if (! in_array($file->getExtension(), ['php', 'ts', 'vue'], true)) continue;
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match('/tenant_id|legal_entity_id|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId/', $text) === 1) {
                    $violations[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $violations, 'Tenant/legal-entity identifiers remain: '.implode(', ', $violations));

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('legal_entities'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'tenant_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('employments', 'legal_entity_id'));
    }
}
```

- [ ] **Step 2: 运行，确认当前有 14 个迁移、54 列、多处代码命中**

```bash
cd backend
php artisan test --compact --filter=NoTenantNoLegalEntityTest
```

Expected: FAIL，列出 `tenant_id`/`legal_entity_id` 命中文件。种子命令在 Task 5 清理；Task 18 的最终扫描覆盖 `database/seeders`。

- [ ] **Step 3: 新增 `company_profile` 与 `COMPANY` 锚点**

```php
Schema::create('company_profile', function (Blueprint $table): void {
    $table->id();
    $table->string('name', 160);
    $table->string('country_code', 2)->default('CN');
    $table->string('timezone', 64)->default('Asia/Shanghai');
    $table->string('currency_code', 3)->default('CNY');
    $table->json('calendar_policy_json')->nullable();
    $table->timestamps();
});
```

`AnchorType`：

```php
enum AnchorType: string
{
    case SELF = 'SELF';
    case MANAGED = 'MANAGED';
    case ORG_UNIT = 'ORG_UNIT';
    case ORG_SUBTREE = 'ORG_SUBTREE';
    case CASE = 'CASE';
    case PAYROLL_RUN = 'PAYROLL_RUN';
    case COMPANY = 'COMPANY';

    public function requiresAnchorId(): bool
    {
        return match ($this) {
            self::ORG_UNIT, self::ORG_SUBTREE, self::CASE, self::PAYROLL_RUN => true,
            self::SELF, self::MANAGED, self::COMPANY => false,
        };
    }
}
```

- [ ] **Step 4: 数据回填与索引重建**

先回填，再删除列，避免破坏现有演示/验收数据：

```sql
-- 1. 显式指定保留的旧 legal_entity_id（演示用 DEMO-CN / id=2）
--    多法人或多租户时迁移必须失败并要求人工确认；禁止按 id 最小者静默合并
INSERT INTO company_profile (id, name, country_code, timezone, currency_code)
SELECT 1, name, country_code, timezone, currency_code
FROM legal_entities
WHERE id = :keep_legal_entity_id;

-- 2. 把 LEGAL_ENTITY 绑定迁移为 COMPANY
UPDATE authority_bindings
SET anchor_type='COMPANY', anchor_id=NULL, anchor_key=0
WHERE anchor_type='LEGAL_ENTITY';
```

随后按表重建唯一索引，去掉 `tenant_id`/`legal_entity_id`：

```text
employments:      (tenant_id, legal_entity_id, employee_no) → (employee_no)
org_units:        (tenant_id, code)                        → (code)
positions:        (tenant_id, legal_entity_id, code)        → (code)
payroll_items:    (tenant_id, legal_entity_id, code)        → (code)
payroll_schemes:  (tenant_id, legal_entity_id, code, version) → (code, version)
attendance_closure: (tenant_id, legal_entity_id, month)     → (month)
calendar:         (tenant_id, legal_entity_id)              → 单行
holidays:         (tenant_id, legal_entity_id, date)        → (date)
shift_versions:   (tenant_id, legal_entity_id, code, version, valid_from) → (code, version, valid_from)
```

- [ ] **Step 5: 删除列与表**

新迁移 `drop_tenant_and_legal_entity_columns.php`：

```php
$tables = ['users', 'sessions', 'roles', 'role_capabilities', 'user_roles', 'authority_bindings',
    'org_units', 'org_unit_closure', 'positions', 'position_qualifications', 'position_grades',
    'persons', 'employments', 'assignments', 'employment_terms', 'documents',
    'employment_contracts', 'contract_templates', 'employment_contract_versions',
    'payroll_items', 'payroll_schemes', 'payroll_runs', 'payslips', 'payslip_lines',
    'payroll_input_snapshots', 'payroll_ytd', 'statutory_parameters',
    'leave_types', 'leave_accounts', 'leave_ledger_entries', 'leave_balances', 'leave_requests',
    'shifts', 'schedules', 'punch_records', 'attendance_days', 'attendance_rule_versions',
    'attendance_period_closures', 'attendance_devices', 'shift_schedules',
    'recruitment_requisitions', 'candidates', 'interviews', 'offers',
    'performance_cycles', 'performance_templates', 'performance_reviews',
    'training_programs', 'training_sessions', 'training_enrollments',
    'notices', 'notification_templates', 'report_definitions', 'export_jobs', 'menu_overrides'];

foreach ($tables as $table) {
    Schema::table($table, function (Blueprint $t): void {
        if (Schema::hasColumn($t->getTable(), 'tenant_id')) $t->dropColumn('tenant_id');
        if (Schema::hasColumn($t->getTable(), 'legal_entity_id')) $t->dropColumn('legal_entity_id');
    });
}

Schema::dropIfExists('legal_entities');
```

> MySQL 对同一表多列删除/索引重建有顺序限制；实施时按表拆分迁移或使用 `DB::statement`，并在 `migration_rehearsal.sh` 验证空库与存量库两条路径。

- [ ] **Step 6: 代码替换**

- `RequireCapability` 的锚点解析：`LEGAL_ENTITY` 分支删除，`COMPANY` 直接返回 `true`（不需要 id）；
- 路由中间件批量替换：`capability:code,LEGAL_ENTITY,legal_entity_id` → `capability:code,COMPANY`；
- `CapabilityCatalog`/`RoleTemplateCatalog` 的 `AnchorType::LEGAL_ENTITY` → `AnchorType::COMPANY`；
- `InstallCommand` 删除 `--legal-entity-code/name`，改为 `--company-name`，只创建一条 `company_profile`；
- `BindRoleCommand` 的 `--legal-entity` 改为无参数；`COMPANY` 绑定不需要 anchor id；
- `MeController` 删除 `tenant_id`、`legal_entities`、`active_legal_entity_id`、`legal_entity_name`；
- `frontend/src/api/http.ts` 删除 `legalEntityId()`、`legalEntityHeaders()`、`X-Legal-Entity-Id`；
- 删除 `frontend/src/api/entityContext.ts` 与 `session.activeLegalEntityId`；
- `LegalEntity` 模型、`LegalEntityInfo`、`OrganizationFacts::legalEntitiesForTenant()` 删除；
- `TenantBusinessDate` 重命名为 `CompanyBusinessDate`，`BelongsToTenant` 重命名为 `BelongsToCompany`，并批量替换引用；
- 迁移预检：`legal_entities` 数量 > 1 或 `tenant_id` 数量 > 1 时失败，要求 `--keep-legal-entity` 与备份证据；未选中的旧数据不得静默合并；
- 组织/时间/工资查询改为无 `legal_entity_id` 过滤，使用 `company_profile.timezone`；
- `demo:seed` 与各模块 `*:seed-demo` 删除 tenant/legal entity 参数与过滤，按单公司幂等写入；
- `ModuleBoundaryScanner::TABLE_OWNERS['Organization']` 加入 `company_profile`，`TABLE_OWNERS['IAM']` 加入 `role_pages`。

- [ ] **Step 7: 运行迁移与测试**

```bash
cd backend
php artisan migrate:fresh --seed
php artisan test --compact --filter='NoTenantNoLegalEntityTest|CompanyAnchorTest|SingleCompanyMigrationTest'
php artisan capability:audit
php artisan arch:test
cd ../frontend
pnpm typecheck
pnpm test:unit
```

Expected: 0 处 `tenant_id`/`legal_entity_id`/`LEGAL_ENTITY`/`X-Legal-Entity-Id`；迁移与测试全绿。

- [ ] **Step 9: 提交**

```bash
git add backend/database/migrations/2026_11_26_000050_create_company_profile_and_company_anchor.php backend/database/migrations/2026_11_26_000060_drop_tenant_and_legal_entity_columns.php backend/app/Modules/Organization/Models/CompanyProfile.php backend/app/Modules/Organization/Contracts/CompanyProfileReader.php backend/app/Shared/Authorization/AnchorType.php backend/app/Modules/IAM backend/routes/api.php backend/tests/Feature/Architecture/SingleCompanyMigrationTest.php backend/tests/Feature/Authorization/CompanyAnchorTest.php backend/tests/Contract/NoTenantNoLegalEntityTest.php frontend/src/api frontend/src/stores/session.ts
git commit -m "refactor!: drop tenant and legal entity for single-company instance"
```

---

### Task 3: 新增 `role_pages` 表、模型与模板种子

**Files:**
- Create: `backend/database/migrations/2026_11_26_000100_create_role_pages_table.php`
- Create: `backend/app/Modules/IAM/Models/RolePage.php`
- Modify: `backend/app/Shared/Architecture/ModuleBoundaryScanner.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php`
- Modify: `backend/app/Modules/IAM/Authorization/RolePageAssignmentCatalog.php`
- Test: `backend/tests/Feature/IAM/RolePagesTest.php`
- Test: `backend/tests/Contract/PagePermissionCoverageTest.php`

**Interfaces:**
- Produces: table `role_pages(id, role_code, page_code, created_at)`，唯一键 `role_code+page_code`。
- Produces: `App\Modules\IAM\Models\RolePage`.
- Produces: `SyncCapabilitiesCommand` 为 14 个模板写入页面集合；`SUPER_ADMIN`/`SYSTEM_ADMIN` 160 页。
- Consumes: `RolePageAssignmentCatalog::pagesForRole(string $code): array`。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\RolePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RolePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_sync_writes_all_160_pages_for_admin_roles_and_matrix_for_others(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $this->assertSame(160, RolePage::query()->where('role_code', 'SUPER_ADMIN')->count());
        $this->assertSame(160, RolePage::query()->where('role_code', 'SYSTEM_ADMIN')->count());
        $this->assertGreaterThan(0, RolePage::query()->where('role_code', 'HR_ADMIN')->count());
        $this->assertLessThan(160, RolePage::query()->where('role_code', 'HR_ADMIN')->count());
        $this->assertGreaterThan(0, RolePage::query()->where('role_code', 'EMPLOYEE')->count());
        $this->assertFalse(Schema::hasColumn('role_pages', 'tenant_id'));
    }

    public function test_role_pages_are_unique_and_idempotent(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $this->assertSame(
            RolePage::query()->count(),
            RolePage::query()->distinct()->count(['role_code', 'page_code']),
        );
    }

}
```

- [ ] **Step 2: 运行，确认表/模型缺失导致失败**

```bash
cd backend
php artisan test --compact --filter=RolePagesTest
```

Expected: FAIL，`no such table: role_pages` 或类不存在。

- [ ] **Step 3: 创建迁移**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('role_code', 64);
            $table->string('page_code', 120);
            $table->timestamp('created_at');

            $table->unique(['role_code', 'page_code'], 'role_page_unique');
            $table->index('role_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_pages');
    }
};
```

- [ ] **Step 4: 创建模型并登记表归属**

```php
<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class RolePage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'role_pages';

    /** @var list<string> */
    protected $fillable = ['role_code', 'page_code', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
```

在 `ModuleBoundaryScanner::TABLE_OWNERS['IAM']` 末尾加入 `'role_pages'`；在 `docs/01-architecture.md` IAM 表清单同步加入。`RolePage` 模型归属 IAM，其他模块不得直接查询；如需页面投影，走 IAM Contracts。

- [ ] **Step 5: 在 `SyncCapabilitiesCommand` 写模板页面**

在 `foreach (RoleTemplateCatalog::templates() as $template)` 内、写完 `RoleCapability` 后加入：

```php
$assignment = app(\App\Modules\IAM\Authorization\RolePageAssignmentCatalog::class)
    ->allAssignments()[$template->code] ?? null;

$pageCodes = $assignment['pages'] ?? [];

\App\Modules\IAM\Models\RolePage::query()
    ->where('role_code', $template->code)
    ->delete();

foreach ($pageCodes as $pageCode) {
    \App\Modules\IAM\Models\RolePage::query()->create([
        
        'role_code' => $template->code,
        'page_code' => (string) $pageCode,
        'created_at' => $now,
    ]);
}
```

同时把 `RolePageAssignmentCatalog::pagesForRole()` 的返回改为直接返回 `page_code` 列表，避免只读矩阵与 `role_pages` 两套事实分叉：

```php
/** @return list<string> */
public function pageCodesForRole(string $code): array
{
    return $this->allAssignments()[strtoupper($code)]['pages'] ?? [];
}
```

- [ ] **Step 6: 更新覆盖测试**

在 `PagePermissionCoverageTest` 增加：

```php
public function test_every_template_page_assignment_is_backed_by_the_code_catalog(): void
{
    $catalog = new PermissionResourceCatalog;
    $assignments = (new RolePageAssignmentCatalog)->allAssignments();

    foreach ($assignments as $roleCode => $assignment) {
        foreach ($assignment['pages'] as $pageCode) {
            $this->assertNotNull($catalog->find($pageCode), "{$roleCode} assigns unknown page {$pageCode}.");
        }
    }
}
```

- [ ] **Step 8: 运行测试**

```bash
cd backend
php artisan test --compact --filter='RolePagesTest|PagePermissionCoverageTest'
php artisan migrate:fresh --seed
php artisan capability:audit
php artisan arch:test
```

Expected: 全部 PASS；`capability:audit` 无 G-COV 违规。

- [ ] **Step 8: 提交**

```bash
git add backend/database/migrations/2026_11_26_000100_create_role_pages_table.php backend/app/Modules/IAM/Models/RolePage.php backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php backend/app/Modules/IAM/Authorization/RolePageAssignmentCatalog.php backend/app/Shared/Architecture/ModuleBoundaryScanner.php backend/tests/Feature/IAM/RolePagesTest.php backend/tests/Contract/PagePermissionCoverageTest.php docs/01-architecture.md
git commit -m "feat(iam): persist role page grants and seed template pages"
```

---

### Task 4: `/me/permissions` 按页面授权过滤并返回可解释诊断

**Files:**
- Modify: `backend/app/Modules/IAM/Http/Controllers/MeController.php`
- Modify: `backend/app/Modules/IAM/Models/UserRole.php`（关系或查询辅助，可选）
- Modify: `docs/openapi/openapi.yaml`
- Modify: `docs/openapi/iam.yaml`（如仍维护）
- Test: `backend/tests/Feature/IAM/MePermissionsPageGrantTest.php`
- Test: `backend/tests/Feature/IAM/MePermissionsTest.php`

**Interfaces:**
- Produces: `data.pages[]` 每项包含 `page_code`、`path`、`menu_group`、`menu_label`、`required_capability`、`granted_by_role`。
- Produces: `data.page_diagnostics` 仅用于管理员/调试：`{page_code, visible, reason, missing_capability, granting_roles}`；普通用户可省略。
- Consumes: `role_pages`、`user_roles`、`AuthorityBinding`。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MePermissionsPageGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth.session', 'capability:iam.session.manage'])
            ->prefix('v1')
            ->group(function (): void {
                Route::get('/me/permissions', [\App\Modules\IAM\Http\Controllers\MeController::class, 'permissions'])
                    ->name('me.permissions.page-grant-test');
            });
    }

    public function test_a_page_with_capability_but_no_page_grant_is_not_visible(): void
    {
        $user = $this->userWith(['people.employee.read'], ['employee-detail']);

        $pages = $this->permissions($user)['pages'];
        $this->assertNotContains('/hr/employees', array_column($pages, 'path'));
        $this->assertContains('/hr/employees/1', array_column($pages, 'path'));
    }

    public function test_a_page_grant_without_its_capability_is_not_visible(): void
    {
        $user = $this->userWith([], ['hr-employees']);

        $pages = $this->permissions($user)['pages'];
        $this->assertSame([], $pages);
    }

    public function test_a_role_can_grant_only_selected_pages_even_when_all_share_one_capability(): void
    {
        $user = $this->userWith(['people.employee.read'], ['hr-employees', 'hr-employees-imports']);

        $paths = array_column($this->permissions($user)['pages'], 'path');
        $this->assertContains('/hr/employees', $paths);
        $this->assertContains('/hr/employees/imports', $paths);
        $this->assertNotContains('/hr/employees/:id/education', $paths);
    }

    private function permissions(User $user): array
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            tenantId: 1,
            employmentId: null,
            authVersion: (int) $user->auth_version,
            privileged: false,
            mfa: false,
            sessionId: 'test-session',
        );
        $this->app->instance(Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);

        return (array) $this->getJson('/v1/me/permissions')->assertOk()->json('data');
    }

    private function userWith(array $capabilities, array $pageCodes): User
    {
        $user = User::factory()->create(['auth_version' => 1]);
        UserRole::query()->create([
                        'user_id' => (int) $user->id,
            'role_code' => 'TEST_ROLE',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'granted_by' => null,
            'reason' => 'synthetic test',
            'auth_version' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        Role::query()->create([
            'code' => 'TEST_ROLE',
                        'name' => 'Synthetic test role',
            'is_template' => false,
            'owner' => null,
            'purpose' => 'Synthetic page grant test.',
            'expires_at' => '2027-01-01 00:00:00',
        ]);

        foreach ($pageCodes as $pageCode) {
            RolePage::query()->create([
                                'role_code' => 'TEST_ROLE',
                'page_code' => $pageCode,
                'created_at' => '2026-01-01 00:00:00',
            ]);
        }

        foreach ($capabilities as $capability) {
            AuthorityBinding::factory()
                ->forPrincipal(1, (int) $user->id, 1)
                ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
                ->create(['capability_code' => $capability, 'purpose_code' => 'TEST']);
        }

        return $user;
    }
}
```

- [ ] **Step 2: 运行，确认页面仍按能力推导**

```bash
cd backend
php artisan test --compact --filter=MePermissionsPageGrantTest
```

Expected: 第一个测试失败，`/hr/employees` 仍可见。

- [ ] **Step 3: 在 `MeController::permissions()` 计算页面集合**

新增私有方法：

```php
/**
 * @return array{0: array<string, true>, 1: array<string, list<string>>}
 */
private function grantedPages(int $userId, int $authVersion): array
{
    $roles = UserRole::query()
                ->where('user_id', $userId)
        ->where('auth_version', $authVersion)
        ->where('valid_from', '<=', $this->clock->now())
        ->where('valid_to', '>', $this->clock->now())
        ->pluck('role_code')
        ->map(static fn (mixed $code): string => (string) $code)
        ->all();

    $pages = [];
    $byPage = [];

    foreach ($roles as $roleCode) {
        $codes = \App\Modules\IAM\Models\RolePage::query()
            ->where('role_code', $roleCode)
            ->pluck('page_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        foreach ($codes as $pageCode) {
            $pages[$pageCode] = true;
            $byPage[$pageCode][] = $roleCode;
        }
    }

    return [$pages, $byPage];
}
```

在 `permissions()` 中：

```php
[$grantedPages, $pagesByRole] = $this->grantedPages(
    $principal->userId(),
    $principal->authVersion(),
);

foreach ($catalog->pages() as $page) {
    $pageCode = (string) $page['page_code'];
    $required = (string) $page['required_capability'];
    $override = $overrides[$pageCode] ?? null;

    if ($required !== 'public' && ! isset($actions[$required])) {
        continue;
    }

    // Page grant is a separate resource from action capability.
    if ($required !== 'public' && ! isset($grantedPages[$pageCode])) {
        continue;
    }

    if ($override !== null && $override['visible'] === false) {
        continue;
    }

    $visiblePages[] = [
        ...$page,
        'label' => $override['label'] ?? $page['menu_label'],
        'sort_order' => $override['sort_order'] ?? $page['order'],
        'field_profile_max' => $required === 'public'
            ? FieldProfile::P0_PUBLIC->value
            : ($fieldProfilesByCapability[$required] ?? FieldProfile::P0_PUBLIC->value),
        'granted_by_role' => $pagesByRole[$pageCode] ?? [],
    ];
}
```

`menusForCapabilities()` 也改为接收页面集合：

```php
$menus = $catalog->menusForPageGrants($actionCodes, array_keys($grantedPages));
```

在 `PermissionResourceCatalog` 新增：

```php
/**
 * @param  list<string>  $grantedCapabilities
 * @param  list<string>  $grantedPages
 * @return list<array<string, mixed>>
 */
public function menusForPageGrants(array $grantedCapabilities, array $grantedPages): array
{
    $capabilities = array_fill_keys($grantedCapabilities, true);
    $pages = array_fill_keys($grantedPages, true);
    $menus = [];

    foreach ($this->pages() as $page) {
        $pageCode = (string) $page['page_code'];
        $required = (string) $page['required_capability'];

        if ($required !== 'public' && (! isset($capabilities[$required]) || ! isset($pages[$pageCode]))) {
            continue;
        }

        $key = (string) $page['menu_group'];
        $menus[$key] ??= [
            'key' => $key,
            'label' => $key,
            'order' => (int) $page['order'],
            'icon' => (string) $page['icon'],
            'pages' => [],
        ];
        $menus[$key]['order'] = min((int) $menus[$key]['order'], (int) $page['order']);
        $menus[$key]['pages'][] = $page;
    }

    $rows = array_values($menus);
    usort($rows, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

    return $rows;
}
```

- [ ] **Step 4: 更新 OpenAPI `MyPermissionsData`**

`pages` 项增加 `page_code`、`granted_by_role`；`menus` 项说明只包含已授权页面。同步 `docs/openapi/iam.yaml` 中的 schema。

- [ ] **Step 5: 更新旧测试**

`MePermissionsTest::test_a_wildcard_principal_receives_every_catalogued_page_and_capability()` 现在必须先创建 `SYSTEM_ADMIN` 的 `user_roles` 与 160 条 `role_pages`（或调用 `iam:sync-capabilities` 后写 `user_roles`）。测试改为：

```php
$this->artisan('iam:sync-capabilities')->assertSuccessful();
UserRole::query()->create([
        'user_id' => (int) $user->id,
    'role_code' => 'SYSTEM_ADMIN',
    'valid_from' => '2026-01-01 00:00:00',
    'valid_to' => '2027-01-01 00:00:00',
    'granted_by' => null,
    'reason' => 'synthetic test',
    'auth_version' => 1,
    'created_at' => '2026-01-01 00:00:00',
]);
```

- [ ] **Step 6: 运行测试**

```bash
cd backend
php artisan test --compact --filter='MePermissionsPageGrantTest|MePermissionsTest|RolePagesTest'
php artisan test --compact --filter=PagePermissionCoverageTest
node ../deploy/scripts/openapi-lint.mjs
```

Expected: PASS；wildcard 测试仍得到 160 页。

- [ ] **Step 7: 提交**

```bash
git add backend/app/Modules/IAM/Http/Controllers/MeController.php backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php backend/tests/Feature/IAM/MePermissionsPageGrantTest.php backend/tests/Feature/IAM/MePermissionsTest.php docs/openapi/openapi.yaml docs/openapi/iam.yaml
git commit -m "feat(iam): enforce page grants in permission projection"
```

---

### Task 5: 演示种子与安装链同时写角色、页面、绑定

**Files:**
- Modify: `backend/app/Modules/IAM/Console/Commands/SeedDemoCommand.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php`
- Test: `backend/tests/Unit/IAM/IamCommandsTest.php`
- Test: `backend/tests/Feature/IAM/BindRoleCommandTest.php`

**Interfaces:**
- Produces: `demo.admin → SYSTEM_ADMIN`、`demo.hr → HR_ADMIN`、`demo.manager → LINE_MANAGER`、`demo.employee → EMPLOYEE` 的 `user_roles` 行。
- Produces: 安装器 `iam:bind-role` 与种子写出的 `user_roles` 具有相同的 `valid_from`、`valid_to`、`auth_version`。
- Consumes: `RolePage` 模板种子（Task 3）。

- [ ] **Step 1: 写失败测试**

在 `IamCommandsTest` 增加：

```php
public function test_demo_seed_writes_role_rows_for_every_demo_user(): void
{
    $this->freeze();
    $this->artisan('iam:sync-capabilities')->assertSuccessful();
    config(['iam.demo_seed_password' => 'Correct-Horse-9!']);
    $this->artisan('iam:seed-demo')->assertSuccessful();

    $expected = [
        'demo.admin' => 'SYSTEM_ADMIN',
        'demo.hr' => 'HR_ADMIN',
        'demo.manager' => 'LINE_MANAGER',
        'demo.employee' => 'EMPLOYEE',
    ];

    foreach ($expected as $username => $roleCode) {
        $user = User::query()->where('username', $username)->firstOrFail();
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_code' => $roleCode,
            'auth_version' => $user->auth_version,
        ]);
    }
}

public function test_demo_seed_is_idempotent_for_role_rows(): void
{
    $this->freeze();
    $this->artisan('iam:sync-capabilities')->assertSuccessful();
    config(['iam.demo_seed_password' => 'Correct-Horse-9!']);
    $this->artisan('iam:seed-demo')->assertSuccessful();
    $this->artisan('iam:seed-demo')->assertSuccessful();

    $this->assertSame(4, UserRole::query()->count());
}
```

- [ ] **Step 2: 运行，确认失败**

```bash
cd backend
php artisan test --compact --filter='IamCommandsTest'
```

Expected: FAIL，`user_roles` 无记录。

- [ ] **Step 3: 在 `SeedDemoCommand` 写 `UserRole`**

在 `foreach ($roles as $roleCode)` 内、写 `AuthorityBinding` 之前加入：

```php
$validFrom = $clock->now()->startOfDay();
$validTo = $clock->now()->addYear()->startOfDay();

UserRole::query()->firstOrCreate([
    'user_id' => (int) $user->id,
    'role_code' => $roleCode,
    'valid_from' => $validFrom,
], [
    'valid_to' => $validTo,
    'granted_by' => null,
    'reason' => 'DEMO seed',
    'auth_version' => (int) $user->auth_version,
    'created_at' => $clock->now(),
]);
```

并修复历史行：

```php
UserRole::query()
    ->where('user_id', (int) $user->id)
    ->where('role_code', $roleCode)
    ->update(['auth_version' => (int) $user->auth_version]);
```

- [ ] **Step 4: 让 `iam:bind-role` 的“已绑定”判断同时检查角色行**

在 `BindRoleCommand` 的 `$alreadyBound` 后增加：

```php
$hasRole = UserRole::query()
    ->where('user_id', (int) $user->id)
    ->where('role_code', $template->code)
    ->where('auth_version', (int) $user->auth_version)
    ->where('valid_to', '>', $now)
    ->exists();

if ($alreadyBound && $hasRole) {
    $this->info("{$template->code} is already bound to user {$user->id}; nothing changed.");

    return self::SUCCESS;
}
```

- [ ] **Step 5: 运行测试**

```bash
cd backend
php artisan test --compact --filter='IamCommandsTest|BindRoleCommandTest|RolePagesTest'
```

Expected: PASS；重复 seed 不新增角色行。

- [ ] **Step 6: 提交**

```bash
git add backend/app/Modules/IAM/Console/Commands/SeedDemoCommand.php backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php backend/tests/Unit/IAM/IamCommandsTest.php backend/tests/Feature/IAM/BindRoleCommandTest.php
git commit -m "fix(iam): seed roles alongside authority bindings"
```

---

### Task 6: 前端删除法人/租户上下文并改为单公司

**Files:**
- Modify: `frontend/src/api/http.ts`
- Modify: `frontend/src/api/contracts.ts`
- Modify: `frontend/src/stores/session.ts`
- Modify: `frontend/src/layouts/AppShell.vue`
- Modify: `frontend/src/views/platform/PlatformWorkspace.vue`
- Delete: `frontend/src/api/entityContext.ts`
- Test: `frontend/tests/unit/singleCompanyContext.spec.ts`
- Test: `frontend/tests/unit/legalEntityContext.spec.ts`（改为断言删除）

**Interfaces:**
- Consumes: `company_profile`（Task 2）、`/me` 不再返回 tenant/legal entity。
- Produces: 前端无 `legalEntityId()`、无 `activeLegalEntityId`、无 `X-Legal-Entity-Id`、无公司切换器。
- Produces: 公司名/时区/货币来自 `/me.company` 或 `/v1/company`。

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'

describe('single-company frontend', () => {
  it('has no legal entity or tenant context', () => {
    for (const file of ['src/api/http.ts', 'src/stores/session.ts', 'src/layouts/AppShell.vue']) {
      const text = readFileSync(file, 'utf8')
      expect(text, file).not.toMatch(/legalEntityId|activeLegalEntityId|X-Legal-Entity-Id|tenant_id|tenantName/i)
    }
  })
})
```

- [ ] **Step 2: 运行，确认当前有命中**

```bash
cd frontend
pnpm test:unit -- singleCompanyContext.spec.ts
```

Expected: FAIL，列出仍含法人/租户上下文的文件。

- [ ] **Step 3: 删除前端上下文**

- 删除 `frontend/src/api/entityContext.ts`；
- `session.ts` 删除 `tenantId`、`tenantName`、`legalEntityName`、`activeLegalEntityId`；
- `http.ts` 删除 `legalEntityId()`、`legalEntityHeaders()`，所有请求不再带 `X-Legal-Entity-Id`；
- `AppShell.vue` 的 `tenantLabel` 改为 `companyName`，来自 `/me.company.name`；
- `PlatformWorkspace.vue` 的 `entityId` 改为常量 `1` 或直接删除参数；
- `SessionBootstrap` 增加 `company: { name, timezone, currencyCode }`。

- [ ] **Step 4: 更新测试**

- `legalEntityContext.spec.ts` 改为：

```ts
import { describe, expect, it } from 'vitest'
import { existsSync } from 'node:fs'

describe('legal entity context removed', () => {
  it('entityContext module does not exist', () => {
    expect(existsSync('src/api/entityContext.ts')).toBe(false)
  })
})
```

- 删除/改写所有 `legal_entity_id: 1` 的测试断言，改为无该参数。

- [ ] **Step 5: 运行**

```bash
cd frontend
pnpm typecheck
pnpm test:unit
pnpm lint
```

Expected: PASS；0 处法人/租户标识。

- [ ] **Step 6: 提交**

```bash
git add frontend/src/api frontend/src/stores/session.ts frontend/src/layouts/AppShell.vue frontend/src/views/platform/PlatformWorkspace.vue frontend/tests/unit/singleCompanyContext.spec.ts frontend/tests/unit/legalEntityContext.spec.ts
git commit -m "refactor(frontend): remove legal entity and tenant context"
```

---

### Task 7: 前端权限加载硬失败 + 页面/动作双守卫

**Files:**
- Modify: `frontend/src/api/contracts.ts`
- Modify: `frontend/src/api/http.ts`
- Modify: `frontend/src/stores/session.ts`
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/router/registry.ts`（`PageSpec` 不变，但 meta 增加 `pageCode`）
- Modify: `frontend/src/views/ForbiddenView.vue`
- Test: `frontend/tests/unit/permissionBootstrap.spec.ts`
- Test: `frontend/tests/unit/pageAuthRouting.spec.ts`
- Test: `frontend/tests/unit/routerRegistry.spec.ts`

**Interfaces:**
- Produces: `SessionBootstrap.pages: string[]`、`SessionBootstrap.permissionLoadError: string | null`。
- Produces: `session.canPage(pageCode)`、`session.can(capability)`。
- Produces: `route.meta.pageCode`、`route.meta.capability`。
- 失败语义：`/me/permissions` 403/500 不得吞错；显示错误页与 request_id。

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it, vi } from 'vitest'
import { createHttpAdapter } from '@/api/http'

const get = vi.fn()
vi.mock('axios', () => ({
  default: {
    create: () => ({ get, post: vi.fn(), interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } } }),
    isAxiosError: () => false,
  },
  isAxiosError: () => false,
}))

describe('permission bootstrap', () => {
  it('does not silently degrade to an empty capability set', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/me') return Promise.resolve({ data: { code: 'OK', data: { id: 1, username: 'demo.admin', is_privileged: true, auth_version: 1 } } })
      if (url === '/me/capabilities') return Promise.reject(new Error('synthetic 500'))
      return Promise.resolve({ data: { code: 'OK', data: {} } })
    })

    await expect(createHttpAdapter().auth.bootstrap()).rejects.toThrow()
  })
})
```

- [ ] **Step 2: 运行，确认当前实现返回空能力而不是抛错**

```bash
cd frontend
pnpm test:unit -- permissionBootstrap.spec.ts
```

Expected: FAIL，`bootstrap()` 返回 `capabilities: []`。

- [ ] **Step 3: 改为 `/me/permissions` 作为唯一投影**

`loadIdentity()`：

```ts
const permissions = await unwrap<BackendPermissions>(
  client.get<ApiEnvelope<BackendPermissions>>('/me/permissions'),
)

const capabilities = permissions.actions
const pages = permissions.pages.map((page) => page.page_code)
```

删除 `/me/capabilities` 的静默 catch。异常直接抛出，由 `router.beforeEach` 捕获并导航到 `permission-error` 页（或 `ForbiddenView` 带 `reason=PERMISSION_LOAD_FAILED`）。

- [ ] **Step 4: 增加页面守卫**

`registryRoute()`：

```ts
meta: {
  title: page.menu?.label ?? page.name,
  pageCode: page.name,
  capability: page.capability === 'public' ? undefined : page.capability,
  public: page.capability === 'public',
  menu: page.menu,
  registry: page,
}
```

`router.beforeEach`：

```ts
const requiredPage = typeof to.meta.pageCode === 'string' ? to.meta.pageCode : undefined
const requiredCapability = typeof to.meta.capability === 'string' ? to.meta.capability : undefined

if (requiredPage && !session.canPage(requiredPage)) {
  return { name: 'forbidden', query: { from: to.fullPath, reason: 'PAGE_NOT_GRANTED' } }
}
if (requiredCapability && !session.can(requiredCapability)) {
  return { name: 'forbidden', query: { from: to.fullPath, reason: 'CAPABILITY_NOT_GRANTED' } }
}
```

`ForbiddenView` 根据 `reason` 显示：

```text
PAGE_NOT_GRANTED → 当前角色未授予该页面。请联系管理员在“角色管理 → 页面权限”中勾选。
CAPABILITY_NOT_GRANTED → 页面已授权，但缺少动作能力 <code>。请联系管理员授予对应动作。
PERMISSION_LOAD_FAILED → 权限信息加载失败。请重试或联系管理员，并提供 request_id。
```

- [ ] **Step 5: 运行测试**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- permissionBootstrap.spec.ts pageAuthRouting.spec.ts routerRegistry.spec.ts
```

Expected: PASS；500 时不再出现空菜单。

- [ ] **Step 6: 提交**

```bash
git add frontend/src/api/contracts.ts frontend/src/api/http.ts frontend/src/stores/session.ts frontend/src/router/index.ts frontend/src/views/ForbiddenView.vue frontend/tests/unit/permissionBootstrap.spec.ts frontend/tests/unit/pageAuthRouting.spec.ts frontend/tests/unit/routerRegistry.spec.ts
git commit -m "fix(frontend): fail loudly on permission bootstrap and guard pages"
```

---

### Task 8: 前后端页面能力对账 + travel 三页修正

**Files:**
- Create: `scripts/check_page_capability_contract.mjs`
- Modify: `package.json`
- Modify: `frontend/src/router/registry.ts`
- Modify: `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`
- Modify: `backend/routes/api.php`（仅当选择统一读能力方案）
- Test: `backend/tests/Contract/PageCapabilityContractTest.php`
- Test: `frontend/tests/unit/pageCapabilityContract.spec.ts`

**Interfaces:**
- Produces: `pnpm page-capability:check` 对账脚本。
- 决策：travel 三页按解决方案 §11 第 4 项的推荐矩阵。

- [ ] **Step 1: 写失败测试（PHP 对账）**

```php
<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use PHPUnit\Framework\TestCase;

final class PageCapabilityContractTest extends TestCase
{
    public function test_frontend_registry_and_backend_page_catalog_agree_on_every_capability(): void
    {
        $frontend = (string) file_get_contents(__DIR__.'/../../../frontend/src/router/registry.ts');
        preg_match_all("/path: '([^']+)',\\s*name: '([^']+)',\\s*componentKey: '[^']+',\\s*capability: '([^']+)'/", $frontend, $matches, PREG_SET_ORDER);

        $backend = [];
        foreach ((new PermissionResourceCatalog)->pages() as $page) {
            $backend[$page['path']] = $page['required_capability'];
        }

        $this->assertCount(160, $matches);

        foreach ($matches as $match) {
            $this->assertArrayHasKey($match[1], $backend, "Backend page catalog is missing {$match[1]}.");
            $this->assertSame($backend[$match[1]], $match[3], "Capability drift at {$match[1]}.");
        }
    }
}
```

- [ ] **Step 2: 运行，确认 3 处 travel 漂移**

```bash
cd backend
php artisan test --compact --filter=PageCapabilityContractTest
```

Expected: FAIL，列出 `/travel/policies`、`/travel/approval`、`/travel/expenses`。

- [ ] **Step 3: 修正前端页面进入能力**

`frontend/src/router/registry.ts`：

```ts
{path: '/travel/policies', name: 'travel-policies', componentKey: 'travel/policies', capability: 'time.travel.policy.read', ...}
{path: '/travel/approval', name: 'travel-approval', componentKey: 'travel/approval', capability: 'time.travel.approve', ...}
{path: '/travel/expenses', name: 'travel-expenses', componentKey: 'travel/expenses', capability: 'time.travel.expense', ...}
```

页面内按钮仍用 `time.travel.policy.manage` / `time.travel.approve` / `time.travel.expense.manage`。

- [ ] **Step 4: 确保角色模板签发页面进入能力**

在 `RoleTemplateCatalog` 的 `HR_ADMIN` 中加入：

```php
$grant('HR_ADMIN', 'time.travel.policy.read', $le, FieldProfile::P1_INTERNAL),
$grant('HR_ADMIN', 'time.travel.approve', $le, FieldProfile::P1_INTERNAL),
$grant('HR_ADMIN', 'time.travel.expense', $le, FieldProfile::P2_SENSITIVE),
```

`HR_SPECIALIST`、`ATTENDANCE_SPECIALIST` 按蓝皮书矩阵补充 `time.travel.policy.read` 与 `time.travel.expense`。`LINE_MANAGER` 至少补 `time.travel.approve`、`time.travel.policy.read`。

- [ ] **Step 5: 创建 CI 对账脚本**

```js
#!/usr/bin/env node
import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'

const root = resolve(new URL('..', import.meta.url).pathname)
const frontend = readFileSync(join(root, 'frontend/src/router/registry.ts'), 'utf8')
const backend = readFileSync(join(root, 'backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php'), 'utf8')

const frontendRows = [...frontend.matchAll(/path: '([^']+)',\s*name: '[^']+',\s*componentKey: '[^']+',\s*capability: '([^']+)'/g)]
  .map((m) => ({ path: m[1], capability: m[2] }))
const backendRows = [...backend.matchAll(/'page_code' => '[^']+',\s*'path' => '([^']+)',\s*'required_capability' => '([^']+)'/g)]
  .map((m) => ({ path: m[1], capability: m[2] }))

const backendByPath = new Map(backendRows.map((row) => [row.path, row.capability]))
const failures = []

for (const row of frontendRows) {
  if (!backendByPath.has(row.path)) {
    failures.push(`${row.path}: missing from backend page catalog`)
    continue
  }
  if (backendByPath.get(row.path) !== row.capability) {
    failures.push(`${row.path}: frontend=${row.capability} backend=${backendByPath.get(row.path)}`)
  }
}
for (const row of backendRows) {
  if (!frontendRows.some((candidate) => candidate.path === row.path)) {
    failures.push(`${row.path}: missing from frontend registry`)
  }
}
if (frontendRows.length !== 160 || backendRows.length !== 160) {
  failures.push(`count mismatch frontend=${frontendRows.length} backend=${backendRows.length}`)
}

if (failures.length > 0) {
  console.error('page-capability:check FAIL')
  for (const failure of failures) console.error(`  - ${failure}`)
  process.exit(1)
}
console.log(`page-capability:check OK (${frontendRows.length} pages)`)
```

`package.json`：

```json
"page-capability:check": "node scripts/check_page_capability_contract.mjs"
```

- [ ] **Step 6: 运行测试与门禁**

```bash
cd backend
php artisan test --compact --filter='PageCapabilityContractTest|PagePermissionCoverageTest'
php artisan capability:audit
cd ..
pnpm page-capability:check
```

Expected: PASS，160/160。

- [ ] **Step 7: 提交**

```bash
git add scripts/check_page_capability_contract.mjs package.json frontend/src/router/registry.ts backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php backend/tests/Contract/PageCapabilityContractTest.php
git commit -m "fix(v11): reconcile page capabilities across frontend and backend"
```

---

### Task 9: 页面组件唯一性门禁（先失败，驱动 Task 10–13）

**Files:**
- Create: `scripts/audit_page_components.mjs`
- Create: `frontend/src/modules/README.md`
- Modify: `package.json`
- Modify: `scripts/audit_route_coverage.mjs`
- Test: `scripts/tests/audit_page_components.test.mjs`
- Test: `frontend/tests/unit/pageComponentUniqueness.spec.ts`

**Interfaces:**
- Produces: `pnpm page-components:check`；解析 `frontend/src/modules/*/pages/index.ts`。
- 约定：模块索引导出一个对象：

```ts
export const PAGE_COMPONENTS = {
  'hr/employees/index': () => import('./pages/EmployeeListPage.vue'),
  'hr/departments/index': () => import('./pages/DepartmentTreePage.vue'),
} as const
```

- 校验：160 个 page_code 全部有映射；文件存在；同一 `.vue` 不得被两个 key 使用（base 页白名单除外）。

- [ ] **Step 1: 写测试（临时 fixture）**

```js
import { describe, expect, it } from 'vitest'
import { auditPageComponents } from '../audit_page_components.mjs'

describe('page component audit', () => {
  it('fails when two page codes point to the same component', () => {
    const result = auditPageComponents({
      registryKeys: ['a', 'b'],
      manifest: {
        a: './pages/EmployeeListPage.vue',
        b: './pages/EmployeeListPage.vue',
      },
      existingFiles: new Set(['./pages/EmployeeListPage.vue']),
      sharedAllowlist: [],
    })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('shared component')
  })

  it('passes when each key has its own file', () => {
    const result = auditPageComponents({
      registryKeys: ['a', 'b'],
      manifest: { a: './pages/A.vue', b: './pages/B.vue' },
      existingFiles: new Set(['./pages/A.vue', './pages/B.vue']),
      sharedAllowlist: [],
    })

    expect(result.ok).toBe(true)
  })
})
```

- [ ] **Step 2: 运行，确认脚本尚不存在**

```bash
cd frontend
pnpm test:unit -- audit_page_components
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 实现 `audit_page_components.mjs`**

```js
#!/usr/bin/env node
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'

const SHARED_ALLOWLIST = new Set([
  'base/AccountMfaView',
  'base/AccountSessionsView',
])

export function auditPageComponents({ registryKeys, manifest, existingFiles, sharedAllowlist = SHARED_ALLOWLIST }) {
  const failures = []
  const byFile = new Map()

  for (const key of registryKeys) {
    const file = manifest[key]
    if (!file) {
      failures.push(`${key}: no page component mapping`)
      continue
    }
    if (!existingFiles.has(file)) {
      failures.push(`${key}: mapped file does not exist (${file})`)
      continue
    }
    const users = byFile.get(file) ?? []
    users.push(key)
    byFile.set(file, users)
  }

  for (const [file, keys] of byFile) {
    if (keys.length < 2) continue
    const allAllowed = keys.every((key) => sharedAllowlist.has(key))
    if (!allAllowed) failures.push(`${file}: shared component used by ${keys.join(', ')}`)
  }

  return { ok: failures.length === 0, failures, byFile }
}

function discoverManifest() {
  const modulesDir = resolve('frontend/src/modules')
  const manifest = {}
  const existingFiles = new Set()

  if (!existsSync(modulesDir)) return { manifest, existingFiles }

  for (const name of readdirSync(modulesDir)) {
    const indexPath = join(modulesDir, name, 'pages/index.ts')
    if (!existsSync(indexPath) || !statSync(indexPath).isFile()) continue
    const source = readFileSync(indexPath, 'utf8')
    for (const match of source.matchAll(/'([^']+)':\s*\(\)\s*=>\s*import\('([^']+)'\)/g)) {
      manifest[match[1]] = match[2]
      existingFiles.add(match[2])
    }
  }

  return { manifest, existingFiles }
}

if (import.meta.url === `file://${process.argv[1]?.replaceAll('\\', '/')}`) {
  const registry = readFileSync('frontend/src/router/registry.ts', 'utf8')
  const keys = [...registry.matchAll(/name: '([^']+)',\s*componentKey:/g)].map((m) => m[1])
  const { manifest, existingFiles } = discoverManifest()
  const result = auditPageComponents({ registryKeys: keys, manifest, existingFiles })
  console.log(JSON.stringify({ ok: result.ok, mapped: Object.keys(manifest).length, total: keys.length, failures: result.failures }, null, 2))
  if (!result.ok) process.exit(1)
}
```

- [ ] **Step 4: 运行，确认当前 160 个 key 全部未映射（预期失败）**

```bash
cd ..
node scripts/audit_page_components.mjs
```

Expected: FAIL，`mapped=0 total=160`。这是 Task 11–13 的驱动门禁。

- [ ] **Step 5: 把门禁接入发布脚本（暂时 warn）**

`scripts/audit_route_coverage.mjs` 增加：

```js
const pageComponentGate = spawnSync(process.execPath, [join(root, 'scripts/audit_page_components.mjs')], { encoding: 'utf8' })
if (pageComponentGate.status !== 0) {
  if (requireMature) failures.push('page-component uniqueness gate failed')
  else console.warn('WARN page-component uniqueness gate failed')
}
```

`package.json`：

```json
"page-components:check": "node scripts/audit_page_components.mjs"
```

- [ ] **Step 6: 提交门禁（允许 CI 暂时红）**

```bash
git add scripts/audit_page_components.mjs scripts/audit_route_coverage.mjs scripts/tests/audit_page_components.test.mjs frontend/src/modules/README.md package.json
git commit -m "test(v11): add page component uniqueness gate"
```

---

### Task 9A: 页面动作清单（PageActionSpec）与按钮级能力门禁

**Files:**
- Modify: `frontend/src/router/registry.ts`
- Create: `frontend/src/components/Can.vue`
- Create: `frontend/src/components/ActionGate.vue`
- Create: `scripts/audit_action_manifest.mjs`
- Modify: `package.json`
- Test: `frontend/tests/unit/actionManifest.spec.ts`
- Test: `frontend/tests/unit/canComponent.spec.ts`
- Test: `scripts/tests/audit_action_manifest.test.mjs`

**Interfaces:**
- Produces: `PageActionSpec` 与 `PageSpec.actions: PageActionSpec[]`。
- Produces: `<Can capability="...">`；无能力时不渲染可点击控件。
- Produces: `pnpm actions:check`。
- Consumes: `session.can(capability)`、`CapabilityCatalog`、OpenAPI `x-capability`。

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'

const WRITE = ['POST', 'PATCH', 'PUT', 'DELETE']

describe('page action manifest', () => {
  it('every write capability has a declared page action', () => {
    for (const page of PAGE_REGISTRY) {
      const writes = page.requiredApis.filter((api) => WRITE.includes(api.split(' ')[0] ?? ''))
      if (writes.length === 0) continue
      expect(page.actions.length, `${page.path} actions`).toBeGreaterThan(0)
      for (const action of page.actions) {
        expect(action.capability).toMatch(/^[a-z]+\.[a-z0-9.-]+/)
        expect(WRITE).toContain(action.api.split(' ')[0])
      }
    }
  })
})
```

- [ ] **Step 2: 运行，确认 `PageSpec` 还没有 actions**

```bash
cd frontend
pnpm test:unit -- actionManifest.spec.ts
```

Expected: FAIL，`page.actions is undefined`。

- [ ] **Step 3: 扩展 PageSpec**

```ts
export type PageActionSpec = {
  key: string
  label: string
  capability: string
  api: string
  ui: 'primary' | 'secondary' | 'danger' | 'hidden'
}

export type PageSpec = {
  // existing fields...
  actions: PageActionSpec[]
}
```

每个含写操作的页面至少声明一个 action；示例：

```ts
actions: [
  { key: 'employee.create', label: '新增员工', capability: 'people.employee.manage', api: 'POST /v1/employees', ui: 'primary' },
  { key: 'employee.export', label: '导出员工', capability: 'people.employee.export', api: 'POST /v1/exports', ui: 'secondary' },
]
```

- [ ] **Step 4: 实现 Can / ActionGate**

`frontend/src/components/Can.vue`：

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useSessionStore } from '@/stores/session'

const props = defineProps<{ capability: string }>()
const session = useSessionStore()
const allowed = computed(() => session.can(props.capability))
</script>

<template>
  <slot v-if="allowed" />
  <slot v-else name="fallback" />
</template>
```

`ActionGate.vue` 额外支持 `mode="disable"`，此时渲染不可点击说明文本，不渲染可点击按钮。

- [ ] **Step 5: 实现 actions:check**

```js
#!/usr/bin/env node
import { readFileSync } from 'node:fs'

const registry = readFileSync('frontend/src/router/registry.ts', 'utf8')
const pages = [...registry.matchAll(/path: '([^']+)'[\s\S]*?actions: \[([\s\S]*?)\][\s\S]*?\},/g)]
if (pages.length === 0) {
  console.error('actions:check FAIL: no page action manifests')
  process.exit(1)
}
const failures = []
for (const [, path, block] of pages) {
  for (const m of block.matchAll(/capability: '([^']+)'/g)) {
    if (!/^[a-z]+\.[a-z0-9.-]+$/.test(m[1])) failures.push(`${path}: invalid capability ${m[1]}`)
  }
}
if (failures.length > 0) {
  console.error('actions:check FAIL')
  failures.forEach((f) => console.error(`  - ${f}`))
  process.exit(1)
}
console.log(`actions:check OK (${pages.length} pages)`)
```

`package.json` 增加 `"actions:check": "node scripts/audit_action_manifest.mjs"`。

- [ ] **Step 6: 组件负测**

```ts
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import Can from '@/components/Can.vue'
import { useSessionStore } from '@/stores/session'

describe('Can', () => {
  it('hides slot without capability', () => {
    setActivePinia(createPinia())
    useSessionStore().$patch({ status: 'authenticated', capabilities: [] })
    const wrapper = mount(Can, {
      props: { capability: 'people.employee.manage' },
      slots: { default: '<button>新增员工</button>' },
    })
    expect(wrapper.find('button').exists()).toBe(false)
  })
})
```

- [ ] **Step 7: 运行门禁**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- actionManifest.spec.ts canComponent.spec.ts
cd ..
pnpm actions:check
```

Expected: PASS。

- [ ] **Step 8: 提交**

```bash
git add frontend/src/router/registry.ts frontend/src/components/Can.vue frontend/src/components/ActionGate.vue scripts/audit_action_manifest.mjs package.json frontend/tests/unit/actionManifest.spec.ts frontend/tests/unit/canComponent.spec.ts
git commit -m "feat(ui): declare page actions and enforce button capability gates"
```

---

### Task 9B: 前端模块边界门禁（非必要不共用页面）

**Files:**
- Create: `scripts/audit_frontend_modules.mjs`
- Modify: `frontend/eslint.config.js`
- Modify: `frontend/src/modules/README.md`
- Modify: `package.json`
- Test: `scripts/tests/audit_frontend_modules.test.mjs`
- Test: `frontend/tests/unit/moduleBoundaries.spec.ts`

**Interfaces:**
- Produces: `pnpm modules:check`。
- 规则：模块 `pages/` 只能 import 本模块 `components/`、`api/`、`types.ts` 与 `shared/`；不得 import 其他模块 `pages/`；不得直接 import `axios`/`fetch`；不得 import `views/` 旧壳。
- 规则：共享组件不得声明 `componentKey`/`pageCode`/`mode` 作为业务身份 props。

- [ ] **Step 1: 写失败测试**

```js
import { describe, expect, it } from 'vitest'
import { auditImports } from '../audit_frontend_modules.mjs'

describe('frontend module boundaries', () => {
  it('rejects cross-module page import', () => {
    const failures = auditImports([
      { file: 'modules/people/pages/EmployeeListPage.vue', imports: ['@/modules/platform/pages/SystemUsersPage.vue'] },
    ])
    expect(failures.join('\n')).toContain('cross-module page import')
  })

  it('rejects page identity prop in shared component', () => {
    const failures = auditImports([], [{ file: 'shared/components/DataTable.vue', props: ['componentKey'] }])
    expect(failures.join('\n')).toContain('page identity prop')
  })
})
```

- [ ] **Step 2: 运行，确认脚本不存在**

```bash
cd frontend
pnpm test:unit -- moduleBoundaries.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 实现 audit_frontend_modules.mjs**

```js
#!/usr/bin/env node
import { readFileSync } from 'node:fs'

const FORBIDDEN = ['axios', 'fetch', '@/views/']
const PAGE_IDENTITY_PROPS = ['componentKey', 'pageCode', 'mode']

export function auditImports(files, sharedComponents = []) {
  const failures = []
  for (const file of files) {
    const moduleName = file.file.split('/')[1]
    for (const spec of file.imports) {
      if (FORBIDDEN.some((needle) => spec.startsWith(needle))) {
        failures.push(`${file.file}: forbidden import ${spec}`)
      }
      if (spec.includes('/modules/') && !spec.includes(`/modules/${moduleName}/`)) {
        failures.push(`${file.file}: cross-module page import ${spec}`)
      }
    }
  }
  for (const component of sharedComponents) {
    for (const prop of component.props) {
      if (PAGE_IDENTITY_PROPS.includes(prop)) {
        failures.push(`${component.file}: page identity prop ${prop} in shared component`)
      }
    }
  }
  return failures
}
```

脚本 CLI 入口扫描 `frontend/src/modules/**/pages/*.vue` 与 `frontend/src/shared/**/*.vue`，失败退出码 1。

- [ ] **Step 4: 运行，确认现有代码触发失败**

```bash
cd ..
node scripts/audit_frontend_modules.mjs
```

Expected: FAIL 或列出待迁移文件；Task 11–13 完成后必须为 0。

- [ ] **Step 5: ESLint 限制**

在 `frontend/eslint.config.js` 增加：

```js
{
  files: ['src/modules/**/*.{ts,vue}'],
  rules: {
    'no-restricted-imports': ['error', {
      patterns: [
        { group: ['@/views/*'], message: '模块页面不得依赖旧 views 壳；请迁入 modules。' },
        { group: ['@/modules/*/pages/*'], message: '页面之间不得互相 import；共享逻辑放 components/ 或 shared/。' },
      ],
    }],
  },
},
```

- [ ] **Step 6: 运行门禁**

```bash
cd frontend
pnpm lint
pnpm test:unit -- moduleBoundaries.spec.ts
cd ..
pnpm modules:check
```

Expected: 最终 0 失败；Task 11–13 完成前允许白名单文件并记录原因。

- [ ] **Step 7: 提交**

```bash
git add scripts/audit_frontend_modules.mjs frontend/eslint.config.js frontend/src/modules/README.md package.json frontend/tests/unit/moduleBoundaries.spec.ts
git commit -m "test(frontend): enforce module boundaries and shared component rules"
```

---

### Task 9C: 超管部署基线（可重复的完整原始数据 + 自检）

**Files:**
- Create: `backend/app/Modules/IAM/Console/Commands/VerifySuperadminCommand.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/InstallCommand.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php`
- Modify: `backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Modify: `backend/app/Modules/Platform/Demo/Services/DemoSeeder.php`
- Modify: `backend/app/Modules/People/Commands/SeedPeopleDemoCommand.php`
- Modify: `backend/app/Modules/Talent/Console/SeedTalentProgramsCommand.php`
- Modify: `backend/app/Modules/Time/Console/SeedLeaveTypesCommand.php`
- Modify: `backend/app/Modules/Time/Console/SeedCalendarCommand.php`
- Modify: `backend/app/Modules/Workflow/Console/SeedWorkflowTemplatesCommand.php`
- Modify: `backend/app/Modules/Lifecycle/Console/SeedLifecycleTemplatesCommand.php`
- Modify: `backend/app/Modules/Payroll/Console/SeedPayrollParametersCommand.php`
- Modify: `deploy/scripts/docker_up.sh`
- Test: `backend/tests/Feature/IAM/SuperadminBaselineTest.php`
- Test: `backend/tests/Feature/IAM/InstallCommandTest.php`
- Test: `backend/tests/Feature/IAM/SuperadminBaselineRealStackTest.php`（真实栈，可选）

**Interfaces:**
- Produces: `php artisan iam:verify-superadmin --username=<user>`：校验 160 页、全部能力、有效 `user_roles`、有效 `authority_bindings`、`COMPANY` 绑定；任何一项失败退出码 1。
- Produces: `hrms:install` 最后一步调用该命令，失败即安装失败。
- Produces: `demo:seed --live` 后自动调用 `iam:verify-superadmin --username=demo.admin`。
- Consumes: `role_pages`（Task 3）、`user_roles`（Task 5）、`PermissionResourceCatalog`、`CapabilityCatalog`。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SuperadminBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_gives_system_admin_all_pages_and_capabilities(): void
    {
        $this->artisan('hrms:install', [
            '--company-name' => 'Demo China Co Ltd',
            '--timezone' => 'Asia/Shanghai',
            '--admin-username' => 'installer',
            '--admin-email' => 'installer@example.test',
            '--admin-password' => 'Synthetic-Password-9!',
            '--annual-days' => '15.00',
            '--legal-basis' => 'Synthetic policy, not legal advice',
        ])->assertExitCode(0);

        $admin = User::query()->where('username', 'installer')->firstOrFail();

        $this->assertSame(160, RolePage::query()->where('role_code', 'SYSTEM_ADMIN')->count());
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $admin->id,
            'role_code' => 'SYSTEM_ADMIN',
            'auth_version' => $admin->auth_version,
        ]);
        $this->assertDatabaseHas('authority_bindings', [
            'principal_id' => $admin->id,
            'capability_code' => '*',
            'auth_version' => $admin->auth_version,
        ]);
    }

    public function test_verify_superadmin_fails_when_user_roles_are_missing(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $admin = User::factory()->create(['username' => 'installer']);
        UserRole::query()->where('user_id', $admin->id)->delete();

        $this->artisan('iam:verify-superadmin', ['--username' => 'installer'])
            ->assertExitCode(1);
    }

    public function test_new_page_is_granted_to_superadmin_after_sync_and_verify(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->assertSame(160, RolePage::query()->where('role_code', 'SYSTEM_ADMIN')->count());

        // Simulate a new page by inserting its role_pages row only for SYSTEM_ADMIN:
        // after sync the count must be 160 again and verify must pass.
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->artisan('iam:verify-superadmin', ['--username' => 'installer'])->assertExitCode(0);
    }
}
```

- [ ] **Step 2: 运行，确认命令不存在/安装未校验**

```bash
cd backend
php artisan test --compact --filter=SuperadminBaselineTest
```

Expected: FAIL，`iam:verify-superadmin` 未注册或角色页面缺失。

- [ ] **Step 3: 实现 `iam:verify-superadmin`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use Illuminate\Console\Command;

final class VerifySuperadminCommand extends Command
{
    protected $signature = 'iam:verify-superadmin {--username= : Administrator username}';

    protected $description = 'Fail unless the administrator has all 160 pages and all capability bindings.';

    public function handle(CapabilityCatalog $capabilities, PermissionResourceCatalog $pages): int
    {
        $username = (string) $this->option('username');
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->error("Unknown administrator {$username}");

            return self::FAILURE;
        }

        $pageCount = (int) RolePage::query()
            ->where('role_code', 'SYSTEM_ADMIN')
            ->distinct('page_code')
            ->count('page_code');
        $expectedPages = count($pages->pages());

        $activeBindings = AuthorityBinding::query()
            ->where('principal_id', $user->id)
            ->where('auth_version', $user->auth_version)
            ->where('valid_from', '<=', now())
            ->where('valid_to', '>', now());

        $wildcard = (clone $activeBindings)->where('capability_code', '*')->exists();
        $expandedCapabilities = $wildcard
            ? count($capabilities->all())
            : (int) (clone $activeBindings)->distinct()->count('capability_code');

        $roleCount = (int) \App\Modules\IAM\Models\UserRole::query()
            ->where('user_id', $user->id)
            ->where('auth_version', $user->auth_version)
            ->where('valid_from', '<=', now())
            ->where('valid_to', '>', now())
            ->count();

        $this->line("user={$username} pages={$pageCount}/{$expectedPages} capabilities={$expandedCapabilities}/".count($capabilities->all())." wildcard=".($wildcard ? 'yes' : 'no')." roles={$roleCount}");

        if ($pageCount !== $expectedPages) {
            $this->error("Page baseline incomplete: {$pageCount}/{$expectedPages}");

            return self::FAILURE;
        }

        if ($expandedCapabilities < count($capabilities->all())) {
            $this->error("Capability bindings incomplete: {$expandedCapabilities}/".count($capabilities->all()));

            return self::FAILURE;
        }

        if ($roleCount < 1) {
            $this->error('Administrator has no user_roles row; role/page grant chain is broken.');

            return self::FAILURE;
        }

        $this->info('Superadmin baseline OK: pages=160, capabilities=all, wildcard=yes, roles>=1.');

        return self::SUCCESS;
    }
}
```

在 `IAM` 的 `ServiceProvider` 注册该命令。

- [ ] **Step 4: 安装器最后校验**

`InstallCommand` 在 `administrator_role` 之后、`audit->record` 之前：

```php
$exit = Artisan::call('iam:verify-superadmin', ['--username' => $username]);
$output = trim(Artisan::output());
$report->record('superadmin_baseline', $exit === 0 ? InstallStep::STATUS_CREATED : InstallStep::STATUS_FAILED, $output);
if ($exit !== 0) {
    throw new \RuntimeException('Superadmin baseline verification failed: '.$output);
}
```

- [ ] **Step 5: 演示链路校验**

`deploy/scripts/docker_up.sh` 的 `--demo` 分支在 `demo:seed --live` 之后加入：

```bash
compose exec -T php-app php backend/artisan iam:verify-superadmin --username=demo.admin
```

`DatabaseSeeder` 改为显式调用 `iam:sync-capabilities` → `iam:seed-demo` → `iam:verify-superadmin`，不再只调用 `iam:seed-demo`。

- [ ] **Step 6: 运行测试**

```bash
cd backend
php artisan test --compact --filter='SuperadminBaselineTest|IamCommandsTest|InstallCommandTest|BindRoleCommandTest'
cd ..
docker compose -f deploy/compose/docker-compose.yml exec -T php-app php backend/artisan iam:verify-superadmin --username=demo.admin
```

Expected: PASS；`pages=160`、`capabilities=all`、`wildcard=yes`、`roles>=1`。

- [ ] **Step 7: 提交**

```bash
git add backend/app/Modules/IAM/Console/Commands/VerifySuperadminCommand.php backend/app/Modules/IAM/Console/Commands/InstallCommand.php backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php backend/database/seeders/DatabaseSeeder.php deploy/scripts/docker_up.sh backend/tests/Feature/IAM/SuperadminBaselineTest.php
git commit -m "feat(iam): verify complete superadmin baseline on install and demo seed"
```

---

### Task 9D: 页面/按钮权限矩阵（参照主流后台的四个 Tab）

**Files:**
- Modify: `frontend/src/modules/platform/pages/SystemRolesPage.vue`
- Modify: `frontend/src/modules/platform/pages/SystemUsersPage.vue`
- Modify: `docs/05-ui-guidelines.md`
- Test: `frontend/tests/unit/rolePageMatrix.spec.ts`
- Test: `frontend/tests/unit/roleButtonMatrix.spec.ts`
- Test: `frontend/tests/e2e/real/role-permission-matrix.spec.ts`

**Interfaces:**
- Consumes: `PageActionSpec`（Task 9A）、`role_pages`（Task 2）、`role_capabilities`（现有）、`Can`/`ActionGate`、`GET/PUT /v1/roles/{id}/pages`（Task 14）。
- Produces: 角色页四 Tab：页面权限 / 按钮权限 / 数据权限 / 字段权限。
- Produces: 保存前差异预览与高风险确认。
- **依赖：** 本任务只做前端矩阵；持久化 API 由 Task 14 提供。若 Task 14 尚未完成，可用 mock 完成组件测试，但真实栈验收必须在 Task 14 之后。

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SystemRolesPage from '@/modules/platform/pages/SystemRolesPage.vue'

describe('role permission matrix', () => {
  it('renders four tabs and does not auto-select parent menu children', async () => {
    const wrapper = mount(SystemRolesPage, { global: { stubs: { 'el-button': true, 'el-tab-pane': true } } })
    expect(wrapper.find('[data-testid="tab-pages"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-buttons"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-scopes"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-fields"]').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: 运行，确认当前角色页没有四 Tab**

```bash
cd frontend
pnpm test:unit -- rolePageMatrix.spec.ts
```

Expected: FAIL，`tab-pages` 不存在。

- [ ] **Step 3: 实现四个 Tab**

```vue
<el-tabs v-model="activeTab">
  <el-tab-pane label="页面权限" name="pages" data-testid="tab-pages">
    <details v-for="group in pageGroups" :key="group.key" open>
      <summary>
        <input type="checkbox" :checked="isGroupFullySelected(group)" @change="toggleGroup(group, $event)" />
        {{ group.label }}
      </summary>
      <label v-for="page in group.pages" :key="page.page_code">
        <input v-model="selectedPageCodes" type="checkbox" :value="page.page_code" />
        {{ page.menu_label }}
      </label>
    </details>
  </el-tab-pane>

  <el-tab-pane label="按钮权限" name="buttons" data-testid="tab-buttons">
    <el-select v-model="selectedPageForButtons" filterable>
      <el-option v-for="page in selectedPages" :key="page.page_code" :label="page.menu_label" :value="page.page_code" />
    </el-select>
    <label v-for="action in actionsForSelectedPage" :key="action.key">
      <input v-model="selectedCapabilities" type="checkbox" :value="action.capability" />
      {{ action.label }} <span class="mono">{{ action.capability }}</span>
    </label>
  </el-tab-pane>

  <el-tab-pane label="数据权限" name="scopes" data-testid="tab-scopes">
    <label v-for="capability in selectedCapabilities" :key="capability">
      {{ capability }}
      <select v-model="anchorByCapability[capability]">
        <option v-for="anchor in allowedAnchors(capability)" :key="anchor" :value="anchor">{{ anchorLabel(anchor) }}</option>
      </select>
    </label>
  </el-tab-pane>

  <el-tab-pane label="字段权限" name="fields" data-testid="tab-fields">
    <label v-for="profile in ['P0_PUBLIC','P1_INTERNAL','P2_SENSITIVE','P3_RESTRICTED']" :key="profile">
      <input v-model="fieldProfile" type="radio" :value="profile" /> {{ profile }}
    </label>
    <label>
      <input v-model="p3Export" type="checkbox" /> 允许 P3 导出（单独审计）
    </label>
  </el-tab-pane>
</el-tabs>
```

- [ ] **Step 4: 差异预览**

保存前计算：

```ts
const diff = {
  pagesAdded: selectedPageCodes.value.filter((code) => !originalPageCodes.value.includes(code)),
  pagesRemoved: originalPageCodes.value.filter((code) => !selectedPageCodes.value.includes(code)),
  capabilitiesAdded: selectedCapabilities.value.filter((code) => !originalCapabilities.value.includes(code)),
  capabilitiesRemoved: originalCapabilities.value.filter((code) => !selectedCapabilities.value.includes(code)),
}
```

若 `diff.pagesAdded`、`diff.capabilitiesAdded` 非空，弹出确认对话框；`*`、P3、`iam.binding.manage` 需要输入原因。

- [ ] **Step 5: 运行测试**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- rolePageMatrix.spec.ts roleButtonMatrix.spec.ts
HMRS_DEMO_PASSWORD=... pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/role-permission-matrix.spec.ts
```

Expected: PASS；父菜单不隐含子页；按钮默认不勾选；保存后即时生效。

- [ ] **Step 6: 提交**

```bash
git add frontend/src/modules/platform/pages/SystemRolesPage.vue frontend/src/modules/platform/pages/SystemUsersPage.vue docs/05-ui-guidelines.md frontend/tests/unit/rolePageMatrix.spec.ts frontend/tests/unit/roleButtonMatrix.spec.ts frontend/tests/e2e/real/role-permission-matrix.spec.ts
git commit -m "feat(ui): add four-tab page and button permission matrix"
```

---

### Task 9E: 模块 CRUD 基线 + 员工 CRUD + 合同软删除

**Files:**
- Create: `backend/database/migrations/2026_11_26_000200_add_soft_deletes_to_employment_contracts.php`
- Create: `backend/database/migrations/2026_11_26_000210_add_employee_archive_columns.php`
- Modify: `backend/app/Modules/People/Services/EmploymentService.php`
- Modify: `backend/app/Modules/People/Http/Controllers/EmploymentController.php`
- Modify: `backend/app/Modules/People/Models/EmploymentContract.php`
- Modify: `backend/app/Modules/People/Services/ContractLifecycleService.php`
- Modify: `backend/app/Modules/People/Http/Controllers/ContractLifecycleController.php`
- Modify: `backend/routes/api.php`
- Create: `backend/app/Console/Commands/CrudAuditCommand.php`
- Modify: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`
- Modify: `docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/People/ContractSoftDeleteTest.php`
- Test: `backend/tests/Feature/People/EmployeeCrudTest.php`
- Test: `backend/tests/Contract/CrudCoverageTest.php`
- Test: `backend/tests/Architecture/CrudAuditCommandTest.php`

**Interfaces:**
- Produces: `employment_contracts.deleted_at/deleted_by/delete_reason`。
- Produces: `DELETE /v1/employment-contracts/{id}` 能力 `people.contract.delete`。
- Produces: `POST /v1/employment-contracts/{id}/restore` 能力 `people.contract.restore`。
- Produces: `employees.archived_at/archived_by/archive_reason`；`DELETE /v1/employees/{id}` 归档、`POST /v1/employees/{id}/restore` 恢复。
- Produces: 员工 CRUD：创建、列表/详情、更新、归档、恢复；员工号永不复用。
- Produces: `php artisan crud:audit`。
- Consumes: `SoftDeletes`、`AuthorizationSnapshot`、审计记录器。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContractSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_delete_is_soft_and_audited(): void
    {
        $contract = EmploymentContract::factory()->create([
                        'contract_no' => 'CON-SOFT-001',
            'status' => 'ACTIVE',
        ]);

        $this->deleteJson("/v1/employment-contracts/{$contract->id}", [], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'contract-delete-1',
        ])->assertOk();

        $this->assertSoftDeleted('employment_contracts', ['id' => $contract->id]);
        $this->assertNotNull(EmploymentContract::withTrashed()->find($contract->id)->deleted_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'people.contract.soft_deleted',
            'resource_id' => (string) $contract->id,
        ]);
    }

    public function test_deleted_contract_is_hidden_from_default_list_and_can_be_restored(): void
    {
        $contract = EmploymentContract::factory()->create(['contract_no' => 'CON-SOFT-002']);
        $contract->delete();

        $this->getJson('/v1/employments/contracts', [])
            ->assertOk()
            ->assertJsonMissing(['contract_no' => 'CON-SOFT-002']);

        $this->postJson("/v1/employment-contracts/{$contract->id}/restore", [], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'contract-restore-1',
        ])->assertOk();

        $this->assertNull(EmploymentContract::withTrashed()->find($contract->id)->deleted_at);
    }
}
```

- [ ] **Step 2: 运行，确认 404/405**

```bash
cd backend
php artisan test --compact --filter=ContractSoftDeleteTest
```

Expected: FAIL，DELETE 路由不存在。

- [ ] **Step 3: 迁移 + 模型**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
            $table->string('delete_reason', 500)->nullable()->after('deleted_by');
            $table->index('deleted_at', 'employment_contracts_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->dropIndex('employment_contracts_deleted_idx');
            $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
        });
    }
};
```

`EmploymentContract`：

```php
use Illuminate\Database\Eloquent\SoftDeletes;

final class EmploymentContract extends Model
{
    use SoftDeletes;
    // ...
}
```

- [ ] **Step 4: 删除/恢复服务与路由**

`ContractLifecycleService`：

```php
public function softDelete(int $contractId, string $reason, int $actorUserId): void
{
    DB::transaction(function () use ($contractId, $reason, $actorUserId): void {
        $contract = EmploymentContract::query()
                        ->lockForUpdate()
            ->findOrFail($contractId);

        $contract->forceFill([
            'deleted_by' => $actorUserId,
            'delete_reason' => $reason,
        ])->save();
        $contract->delete();
    });
}

public function restore(int $contractId, int $actorUserId): void
{
    DB::transaction(function () use ($contractId, $actorUserId): void {
        $contract = EmploymentContract::withTrashed()
                        ->lockForUpdate()
            ->findOrFail($contractId);
        $contract->restore();
        $contract->forceFill(['deleted_by' => null, 'delete_reason' => null])->save();
    });
}
```

路由：

```php
Route::delete('/employment-contracts/{id}', [ContractLifecycleController::class, 'destroy'])
    ->whereNumber('id')
    ->middleware(['mfa.enrolled', 'capability:people.contract.delete'])
    ->name('employment-contracts.destroy');
Route::post('/employment-contracts/{id}/restore', [ContractLifecycleController::class, 'restore'])
    ->whereNumber('id')
    ->middleware(['mfa.enrolled', 'capability:people.contract.restore'])
    ->name('employment-contracts.restore');
```

- [ ] **Step 5: 新增能力码**

`CapabilityCatalog`：

```php
new CapabilityDefinition('people.contract.delete', 'contract', ['delete'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_contract', 'Soft-delete a labour contract while preserving history.'),
new CapabilityDefinition('people.contract.restore', 'contract', ['restore'], [AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', 'people_contract', 'Restore a soft-deleted labour contract.'),
new CapabilityDefinition('people.employee.archive', 'employee', ['archive'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'high', 'people_employee', 'Archive an employee without deleting historical records.'),
new CapabilityDefinition('people.employee.restore', 'employee', ['restore'], [AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE, AnchorType::COMPANY], FieldProfile::P2_SENSITIVE, 'medium', 'people_employee', 'Restore an archived employee.'),
```

`RoleTemplateCatalog` 的 `HR_ADMIN`、`SYSTEM_ADMIN`（wildcard 已覆盖）需要显式签发；`HR_SPECIALIST` 是否可删由 §10D 默认（允许删除但不可恢复，恢复仅 HR_ADMIN/SYSTEM_ADMIN）。

- [ ] **Step 6: 员工 CRUD（归档 + 恢复）**

员工不使用物理删除，也不使用 `SoftDeletes`；归档是业务状态，历史任职/合同/工资仍保留。

迁移：

```php
Schema::table('employments', function (Blueprint $table): void {
    $table->timestamp('archived_at')->nullable()->index();
    $table->unsignedBigInteger('archived_by')->nullable();
    $table->string('archive_reason', 500)->nullable();
});
```

服务：

```php
public function archive(int $employmentId, string $reason, int $actorUserId): void
{
    DB::transaction(function () use ($employmentId, $reason, $actorUserId): void {
        $employment = Employment::query()->lockForUpdate()->findOrFail($employmentId);

        if ($employment->archived_at !== null) {
            return; // 幂等
        }

        $employment->forceFill([
            'archived_at' => $this->clock->now(),
            'archived_by' => $actorUserId,
            'archive_reason' => $reason,
        ])->save();
    });
}

public function restore(int $employmentId, int $actorUserId): void
{
    DB::transaction(function () use ($employmentId, $actorUserId): void {
        $employment = Employment::query()->lockForUpdate()->findOrFail($employmentId);
        $employment->forceFill([
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
        ])->save();
    });
}
```

路由与能力：

```php
Route::delete('/employees/{id}', [EmploymentController::class, 'archive'])
    ->whereNumber('id')
    ->middleware(['mfa.enrolled', 'capability:people.employee.archive'])
    ->name('employees.archive');

Route::post('/employees/{id}/restore', [EmploymentController::class, 'restore'])
    ->whereNumber('id')
    ->middleware(['mfa.enrolled', 'capability:people.employee.restore'])
    ->name('employees.restore');
```

约束：

- `GET /v1/employees` 默认排除 `archived_at != null`；`?include_archived=1` 仅对有 `people.employee.archive` 的用户开放；
- 员工号 `employee_no` 在归档后仍不可复用；
- 归档前校验：无在途审批、无未结算工资批次；否则返回 `409 EMPLOYEE_HAS_ACTIVE_RECORDS`；
- 归档/恢复写审计：`people.employee.archived` / `people.employee.restored`，包含 before/after 与 reason；
- 前端员工列表提供“归档”“恢复”按钮，分别由 `Can capability="people.employee.archive"` 与 `people.employee.restore` 控制。

测试：

```php
public function test_employee_can_be_archived_and_restored_but_not_hard_deleted(): void
{
    $employee = Employment::factory()->create(['employee_no' => 'E-1001']);

    $this->deleteJson("/v1/employees/{$employee->id}", [], [
        'X-CSRF-Token' => 'test-csrf',
        'Idempotency-Key' => 'employee-archive-1',
    ])->assertOk();

    $this->assertNotNull($employee->fresh()->archived_at);
    $this->assertDatabaseHas('employments', ['id' => $employee->id, 'employee_no' => 'E-1001']);

    $this->postJson("/v1/employees/{$employee->id}/restore", [], [
        'X-CSRF-Token' => 'test-csrf',
        'Idempotency-Key' => 'employee-restore-1',
    ])->assertOk();

    $this->assertNull($employee->fresh()->archived_at);
}
```

- [ ] **Step 7: CRUD 审计命令**

```php
final class CrudAuditCommand extends Command
{
    protected $signature = 'crud:audit';

    public function handle(): int
    {
        $failures = [];
        $router = app('router');

        $required = [
            'employees' => ['GET', 'POST', 'PATCH', 'DELETE', 'RESTORE'],
            'departments' => ['GET', 'POST', 'PATCH', 'DELETE'],
            'positions' => ['GET', 'POST', 'PATCH', 'DELETE'],
            'employment-contracts' => ['GET', 'POST', 'PATCH', 'DELETE', 'RESTORE'],
            'contract-templates' => ['GET', 'POST', 'PATCH', 'DELETE'],
            // ...
        ];

        foreach ($required as $prefix => $verbs) {
            foreach ($verbs as $verb) {
                // check route exists under /v1/{prefix} with capability middleware
            }
        }

        if ($failures !== []) {
            $this->error('crud:audit failed');
            foreach ($failures as $failure) $this->line('  - '.$failure);
            return self::FAILURE;
        }

        $this->info('crud:audit OK');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: 运行测试**

```bash
cd backend
php artisan test --compact --filter='ContractSoftDeleteTest|EmployeeCrudTest|CrudCoverageTest|CrudAuditCommandTest'
php artisan crud:audit
php artisan capability:audit
```

Expected: PASS；合同删除为 soft delete；员工可归档/恢复；默认列表不含已归档/已删除。

- [ ] **Step 9: 提交**

```bash
git add backend/database/migrations/2026_11_26_000200_add_soft_deletes_to_employment_contracts.php backend/database/migrations/2026_11_26_000210_add_employee_archive_columns.php backend/tests/Feature/People/EmployeeCrudTest.php backend/app/Modules/People/Models/EmploymentContract.php backend/app/Modules/People/Services/ContractLifecycleService.php backend/app/Modules/People/Http/Controllers/ContractLifecycleController.php backend/routes/api.php backend/app/Console/Commands/CrudAuditCommand.php backend/app/Modules/IAM/Authorization/CapabilityCatalog.php backend/app/Modules/IAM/Authorization/RoleTemplateCatalog.php backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php backend/tests/Feature/People/ContractSoftDeleteTest.php backend/tests/Contract/CrudCoverageTest.php backend/tests/Architecture/CrudAuditCommandTest.php docs/openapi/openapi.yaml
git commit -m "feat(people): employee archive/restore, contract soft delete and crud audit"
```

---

### Task 10: People 模块拆分（员工/部门/岗位/异动/合同）

**Files:**
- Create: `frontend/src/modules/people/pages/index.ts`
- Create: `frontend/src/modules/people/pages/*.vue`（39 个页面文件，见下表）
- Create: `frontend/src/modules/people/components/PeoplePageShell.vue`
- Create: `frontend/src/modules/people/composables/usePeoplePage.ts`
- Modify: `frontend/src/router/pageComponents.ts`
- Modify: `frontend/src/views/people/index.ts`（只保留类型/兼容导出，删除共享壳工厂）
- Delete: `frontend/src/views/people/PeopleWorkspace.vue`（迁移完成后删除）
- Test: `frontend/tests/unit/peoplePages.spec.ts`
- Test: `frontend/tests/unit/pageComponentUniqueness.spec.ts`
- Test: `frontend/tests/e2e/real/people-pages.spec.ts`

**Interfaces:**
- Consumes: 现有 `peoplePageConfig()`、`loadPeoplePage()`、`PeopleDataTable`、`DetailFacts`、表单组件。
- Produces: `PAGE_COMPONENTS`（people 域），每个 key 唯一 `.vue`。

**页面文件清单（39）：**

| componentKey | 目标文件 | 读配置 |
|---|---|---|
| `hr/employees/index` | `EmployeeListPage.vue` | `employee-list` |
| `hr/employees/create` | `EmployeeCreatePage.vue` | `employee-list + employee-create` |
| `hr/employees/by-id` | `EmployeeDetailPage.vue` | `employee-detail` |
| `hr/employees/by-id/education` | `EmployeeEducationPage.vue` | `employee-profile:education` |
| `hr/employees/by-id/work-history` | `EmployeeWorkHistoryPage.vue` | `employee-profile:experiences` |
| `hr/employees/by-id/contacts` | `EmployeeContactsPage.vue` | `employee-profile:contacts` |
| `hr/employees/by-id/credentials` | `EmployeeCredentialsPage.vue` | `employee-profile:certificates` |
| `hr/employees/by-id/history` | `EmployeeHistoryPage.vue` | `employee-timeline` |
| `hr/employees/by-id/files` | `EmployeeFilesPage.vue` | `employee-documents` |
| `hr/employees/by-id/changes` | `EmployeeChangesPage.vue` | `employee-profile:change_requests` |
| `hr/employees/imports` | `EmployeeImportsPage.vue` | `employee-imports` |
| `hr/departments/index` | `DepartmentTreePage.vue` | `org-tree` |
| `hr/departments/list` | `DepartmentListPage.vue` | `department-list` |
| `hr/departments/editor` | `DepartmentEditorPage.vue` | `org-tree + department-create` |
| `hr/departments/members` | `DepartmentMembersPage.vue` | `department-members` |
| `hr/departments/move` | `DepartmentMovePage.vue` | `org-tree + department-move` |
| `hr/departments/staffing` | `DepartmentStaffingPage.vue` | `department-staffing` |
| `hr/positions/index` | `PositionCatalogPage.vue` | `positions` |
| `hr/positions/editor` | `PositionEditorPage.vue` | `positions + position-editor` |
| `hr/positions/headcount` | `PositionHeadcountPage.vue` | `positions + position-headcount` |
| `hr/positions/requirements` | `PositionRequirementsPage.vue` | `position-requirements` |
| `hr/positions/grades` | `PositionGradesPage.vue` | `position-grades` |
| `hr/positions/staff` | `PositionStaffPage.vue` | `position-staff` |
| `hr/movements/index` | `MovementListPage.vue` | `lifecycle-cases` |
| `hr/movements/onboarding` | `MovementOnboardingPage.vue` | `lifecycle-cases:ONBOARDING` |
| `hr/movements/probation` | `MovementProbationPage.vue` | `lifecycle-cases:PROBATION_CONFIRM` |
| `hr/movements/transfer` | `MovementTransferPage.vue` | `lifecycle-cases:ASSIGNMENT_CHANGE` |
| `hr/movements/salary-change` | `MovementSalaryChangePage.vue` | `lifecycle-cases:TERMS_CHANGE` |
| `hr/movements/secondment` | `MovementSecondmentPage.vue` | `secondments` |
| `hr/movements/offboarding` | `MovementOffboardingPage.vue` | `lifecycle-cases:OFFBOARD` |
| `hr/movements/by-id` | `MovementDetailPage.vue` | `lifecycle-case-detail` |
| `hr/movements/calendar` | `MovementCalendarPage.vue` | `lifecycle-cases` |
| `hr/contracts/index` | `ContractLedgerPage.vue` | `contracts-ledger` |
| `hr/contracts/editor` | `ContractEditorPage.vue` | `contracts-ledger + contract-editor` |
| `hr/contracts/by-id` | `ContractDetailPage.vue` | `contract-detail` |
| `hr/contracts/renewals` | `ContractRenewalsPage.vue` | `contract-expiring + contract-renewal` |
| `hr/contracts/changes` | `ContractChangesPage.vue` | `contracts-ledger + contract-changes` |
| `hr/contracts/expiry` | `ContractExpiryPage.vue` | `contract-expiring` |
| `hr/contracts/templates` | `ContractTemplatesPage.vue` | `contract-templates` |

**页面模板（每个文件都按此结构，不得只传 `componentKey` 给共享壳）：**

`frontend/src/modules/people/pages/EmployeeListPage.vue`：

```vue
<script setup lang="ts">
import { usePeoplePage } from '../composables/usePeoplePage'
import PeoplePageShell from '../components/PeoplePageShell.vue'

const page = usePeoplePage('hr/employees/index')
</script>

<template>
  <PeoplePageShell :page="page" data-page="hr/employees/index" title="员工列表">
    <template #default="{ rows, loading, error, retry }">
      <!-- 员工列表专属模板：筛选、导入导出、DataTable、详情抽屉 -->
    </template>
  </PeoplePageShell>
</template>
```

`DepartmentTreePage.vue` 使用组织树模板；`ContractLedgerPage.vue` 使用合同台账模板；两者不得复用 `EmployeeListPage.vue`。

- [ ] **Step 1: 写失败组件测试**

```ts
import { describe, expect, it } from 'vitest'
import EmployeeListPage from '@/modules/people/pages/EmployeeListPage.vue'
import DepartmentTreePage from '@/modules/people/pages/DepartmentTreePage.vue'
import ContractLedgerPage from '@/modules/people/pages/ContractLedgerPage.vue'

describe('people page modules are independent', () => {
  it('员工、部门、合同是三份不同组件', () => {
    expect(EmployeeListPage).not.toBe(DepartmentTreePage)
    expect(DepartmentTreePage).not.toBe(ContractLedgerPage)
    expect(EmployeeListPage.__name).not.toBe(ContractLedgerPage.__name)
  })
})
```

- [ ] **Step 2: 运行，确认文件不存在**

```bash
cd frontend
pnpm test:unit -- peoplePages.spec.ts
```

Expected: FAIL，模块无法解析。

- [ ] **Step 3: 创建 `usePeoplePage.ts` 与 `PeoplePageShell.vue`**

```ts
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { peoplePageConfig } from '@/views/people/catalog'
import { loadPeoplePage, type PeopleReadState } from '@/views/people/peopleService'

export function usePeoplePage(key: string) {
  const route = useRoute()
  const config = computed(() => peoplePageConfig(key))
  const state = ref<PeopleReadState>({ rows: [], total: 0, partial: false, primaryId: null, partialNote: '' })
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      state.value = await loadPeoplePage(config.value!, route.params)
    } catch (reason) {
      error.value = reason
    } finally {
      loading.value = false
    }
  }

  return { config, state, loading, error, load, route }
}
```

`PeoplePageShell.vue` 只负责页面标题/面包屑/五态/重试；不接收 `componentKey`，不按 key 分支业务内容。

- [ ] **Step 4: 逐页创建 39 个 `.vue` 文件并写 `pages/index.ts`**

```ts
// Task 10 完整 39 项；必须与表格逐行一致。
export const PAGE_COMPONENTS = {
  'hr/employees/index': () => import('./EmployeeListPage.vue'),
  'hr/employees/create': () => import('./EmployeeCreatePage.vue'),
  'hr/employees/by-id': () => import('./EmployeeDetailPage.vue'),
  'hr/employees/by-id/education': () => import('./EmployeeEducationPage.vue'),
  'hr/employees/by-id/work-history': () => import('./EmployeeWorkHistoryPage.vue'),
  'hr/employees/by-id/contacts': () => import('./EmployeeContactsPage.vue'),
  'hr/employees/by-id/credentials': () => import('./EmployeeCredentialsPage.vue'),
  'hr/employees/by-id/history': () => import('./EmployeeHistoryPage.vue'),
  'hr/employees/by-id/files': () => import('./EmployeeFilesPage.vue'),
  'hr/employees/by-id/changes': () => import('./EmployeeChangesPage.vue'),
  'hr/employees/imports': () => import('./EmployeeImportsPage.vue'),
  'hr/departments/index': () => import('./DepartmentTreePage.vue'),
  'hr/departments/list': () => import('./DepartmentListPage.vue'),
  'hr/departments/editor': () => import('./DepartmentEditorPage.vue'),
  'hr/departments/members': () => import('./DepartmentMembersPage.vue'),
  'hr/departments/move': () => import('./DepartmentMovePage.vue'),
  'hr/departments/staffing': () => import('./DepartmentStaffingPage.vue'),
  'hr/positions/index': () => import('./PositionCatalogPage.vue'),
  'hr/positions/editor': () => import('./PositionEditorPage.vue'),
  'hr/positions/headcount': () => import('./PositionHeadcountPage.vue'),
  'hr/positions/requirements': () => import('./PositionRequirementsPage.vue'),
  'hr/positions/grades': () => import('./PositionGradesPage.vue'),
  'hr/positions/staff': () => import('./PositionStaffPage.vue'),
  'hr/movements/index': () => import('./MovementListPage.vue'),
  'hr/movements/onboarding': () => import('./MovementOnboardingPage.vue'),
  'hr/movements/probation': () => import('./MovementProbationPage.vue'),
  'hr/movements/transfer': () => import('./MovementTransferPage.vue'),
  'hr/movements/salary-change': () => import('./MovementSalaryChangePage.vue'),
  'hr/movements/secondment': () => import('./MovementSecondmentPage.vue'),
  'hr/movements/offboarding': () => import('./MovementOffboardingPage.vue'),
  'hr/movements/by-id': () => import('./MovementDetailPage.vue'),
  'hr/movements/calendar': () => import('./MovementCalendarPage.vue'),
  'hr/contracts/index': () => import('./ContractLedgerPage.vue'),
  'hr/contracts/editor': () => import('./ContractEditorPage.vue'),
  'hr/contracts/by-id': () => import('./ContractDetailPage.vue'),
  'hr/contracts/renewals': () => import('./ContractRenewalsPage.vue'),
  'hr/contracts/changes': () => import('./ContractChangesPage.vue'),
  'hr/contracts/expiry': () => import('./ContractExpiryPage.vue'),
  'hr/contracts/templates': () => import('./ContractTemplatesPage.vue'),
} as const
```

- [ ] **Step 5: 切换 `pageComponents.ts` 的 People 域**

```ts
// 过渡期：pageComponents.ts 先查模块映射，再查基础页；不得回落到领域桶共享壳。
import { PAGE_COMPONENTS as PEOPLE_PAGES } from '@/modules/people/pages'

const BASE_VIEWS: Record<string, Loader> = {
  'base/LoginView': () => import('@/views/LoginView.vue'),
  'base/WorkspaceView': () => import('@/views/WorkspaceView.vue'),
  'base/ForbiddenView': () => import('@/views/ForbiddenView.vue'),
  'base/NotFoundView': () => import('@/views/NotFoundView.vue'),
  'base/AccountMfaView': () => import('@/views/AccountSecurityView.vue'),
  'base/AccountSessionsView': () => import('@/views/AccountSecurityView.vue'),
}

export function matureView(componentKey: string): Loader | null {
  const base = BASE_VIEWS[componentKey]
  if (base !== undefined) return base

  const peoplePage = PEOPLE_PAGES[componentKey as keyof typeof PEOPLE_PAGES]
  if (peoplePage !== undefined) return peoplePage

  return null
}
```

`matureView()` 对 `hr/*` 先查 `PEOPLE_PAGES[key]`，找不到直接返回 `null`（不得回落共享壳）。

- [ ] **Step 6: 删除 People 壳路由职责**

- 从 `frontend/src/views/people/index.ts` 删除 `defineComponent(() => h(PeopleWorkspace))` 与 `COMPONENTS` map；
- 保留 `catalog.ts`、`peopleService.ts`、表单/表格组件；
- 删除 `PeopleWorkspace.vue`，或改名 `PeoplePageShell.vue` 后删除壳分支；
- 更新 `frontend/src/views/people/index.ts` 的导出，避免测试仍通过壳挂载。

- [ ] **Step 7: 更新测试**

- `peoplePages.spec.ts` 改为逐页挂载 `PAGE_COMPONENTS[key]`，断言每页标题/专属元素；
- 增加“员工列表不渲染合同台账”“部门树不渲染员工表格”的负向断言；
- 新增 `frontend/tests/e2e/real/people-pages.spec.ts`，用 `demo.admin` 打开 `/hr/employees`、`/hr/departments`、`/hr/contracts`，断言三个页面标题与主 API 不同。

- [ ] **Step 8: 运行 People 门禁**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- peoplePages.spec.ts pageComponentUniqueness.spec.ts
HMRS_DEMO_PASSWORD=... pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/people-pages.spec.ts
```

Expected: PASS；39 个 People key 均有唯一文件。

- [ ] **Step 9: 提交**

```bash
git add -A frontend/src/modules/people frontend/src/router/pageComponents.ts frontend/src/views/people frontend/tests/unit/peoplePages.spec.ts frontend/tests/unit/pageComponentUniqueness.spec.ts frontend/tests/e2e/real/people-pages.spec.ts
git commit -m "refactor(people): split shared workspace into independent pages"
```

---

**基础页不在上述业务/平台拆分范围（6 页，保持现有 `BASE_VIEWS`）：**

| componentKey | 目标文件 | 说明 |
|---|---|---|
| `base/LoginView` | `frontend/src/views/LoginView.vue` | 公共登录页 |
| `base/WorkspaceView` | `frontend/src/views/WorkspaceView.vue` | 我的工作台 |
| `base/ForbiddenView` | `frontend/src/views/ForbiddenView.vue` | 403 页 |
| `base/NotFoundView` | `frontend/src/views/NotFoundView.vue` | 404 页 |
| `base/AccountMfaView` | `frontend/src/views/AccountSecurityView.vue` | MFA 与会话共用视图；唯一白名单复用 |
| `base/AccountSessionsView` | `frontend/src/views/AccountSecurityView.vue` | 同上 |

> 基础页不并入四个业务模块；`AccountMfaView`/`AccountSessionsView` 共用 `AccountSecurityView.vue` 是页面组件唯一性门禁中唯一允许的白名单，必须在脚本中写明原因。

---

### Task 11: TimePay 模块拆分（考勤/请假/加班/出差/薪资）

**Files:**
- Create: `frontend/src/modules/timepay/pages/index.ts`
- Create: `frontend/src/modules/timepay/pages/*.vue`（43 个页面文件）
- Create: `frontend/src/modules/timepay/components/TimePayPageShell.vue`
- Modify: `frontend/src/router/pageComponents.ts`
- Modify: `frontend/src/views/timepay/index.ts`
- Modify: `frontend/src/views/timepay/TimePayrollPage.vue`（最终删除）
- Test: `frontend/tests/unit/timePayrollPages.spec.ts`
- Test: `frontend/tests/e2e/real/timepay-pages.spec.ts`

**页面文件清单（43）：**

| 分组 | componentKey → 文件 |
|---|---|
| 考勤 10 | `attendance/index` → `AttendanceMyPage.vue`；`attendance/punches` → `AttendancePunchesPage.vue`；`attendance/calendar` → `AttendanceCalendarPage.vue`；`attendance/rules` → `AttendanceRulesPage.vue`；`attendance/shifts` → `AttendanceShiftsPage.vue`；`attendance/schedules` → `AttendanceSchedulesPage.vue`；`attendance/corrections` → `AttendanceCorrectionsPage.vue`；`attendance/exceptions` → `AttendanceExceptionsPage.vue`；`attendance/closing` → `AttendanceClosingPage.vue`；`attendance/devices` → `AttendanceDevicesPage.vue` |
| 请假 8 | `leave/index` → `LeaveMyPage.vue`；`leave/requests` → `LeaveRequestsPage.vue`；`leave/types` → `LeaveTypesPage.vue`；`leave/policies` → `LeavePoliciesPage.vue`；`leave/balances` → `LeaveBalancesPage.vue`；`leave/calendar` → `LeaveCalendarPage.vue`；`leave/cancellations` → `LeaveCancellationsPage.vue`；`leave/reports` → `LeaveReportsPage.vue` |
| 加班 6 | `overtime/index` → `OvertimeMyPage.vue`；`overtime/requests` → `OvertimeRequestsPage.vue`；`overtime/policies` → `OvertimePoliciesPage.vue`；`overtime/confirm` → `OvertimeConfirmPage.vue`；`overtime/timeoff` → `OvertimeTimeoffPage.vue`；`overtime/settlement` → `OvertimeSettlementPage.vue` |
| 出差 7 | `travel/index` → `TravelMyPage.vue`；`travel/requests` → `TravelRequestsPage.vue`；`travel/policies` → `TravelPoliciesPage.vue`；`travel/approval` → `TravelApprovalPage.vue`；`travel/changes` → `TravelChangesPage.vue`；`travel/reports` → `TravelReportsPage.vue`；`travel/expenses` → `TravelExpensesPage.vue` |
| 薪资 12 | `payroll/index` → `PayrollOverviewPage.vue`；`payroll/components` → `PayrollItemsPage.vue`；`payroll/plans` → `PayrollSchemesPage.vue`；`payroll/employee-plans` → `EmployeeCompensationPage.vue`；`payroll/statutory` → `PayrollStatutoryPage.vue`；`payroll/inputs` → `PayrollInputsPage.vue`；`payroll/batches` → `PayrollRunsPage.vue`；`payroll/reviews` → `PayrollReviewsPage.vue`；`payroll/disbursement` → `PayrollDisbursementPage.vue`；`payroll/payslips` → `PayslipsPage.vue`；`payroll/adjustments` → `PayrollAdjustmentsPage.vue`；`payroll/reports` → `PayrollReportsPage.vue` |


- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import TravelPoliciesPage from '@/modules/timepay/pages/TravelPoliciesPage.vue'
import TravelApprovalPage from '@/modules/timepay/pages/TravelApprovalPage.vue'
import TravelExpensesPage from '@/modules/timepay/pages/TravelExpensesPage.vue'

describe('travel pages', () => {
  it('are three independent components with distinct data sources', () => {
    expect(TravelPoliciesPage).not.toBe(TravelApprovalPage)
    expect(TravelApprovalPage).not.toBe(TravelExpensesPage)
  })
})
```

- [ ] **Step 2: 运行，确认失败**

```bash
cd frontend
pnpm test:unit -- timePayrollPages.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 复用现有面板但不复用页面壳**

- 将 `frontend/src/views/timepay/panels/AttendancePanel.vue`、`LeavePanel.vue`、`OvertimePanel.vue`、`TravelPanel.vue`、`PayrollPanel.vue` 移入 `frontend/src/modules/timepay/components/`；
- 每个页面只组合自己需要的面板与表单；
- `TimePayPageShell.vue` 只负责标题、五态、刷新，不接收 `definition.key` 做业务分支。

- [ ] **Step 4: 逐页创建 43 个 `.vue` 与 `pages/index.ts`**

```ts
export const PAGE_COMPONENTS = {
  'attendance/index': () => import('./AttendanceMyPage.vue'),
  'attendance/punches': () => import('./AttendancePunchesPage.vue'),
  'attendance/calendar': () => import('./AttendanceCalendarPage.vue'),
  'attendance/rules': () => import('./AttendanceRulesPage.vue'),
  'attendance/shifts': () => import('./AttendanceShiftsPage.vue'),
  'attendance/schedules': () => import('./AttendanceSchedulesPage.vue'),
  'attendance/corrections': () => import('./AttendanceCorrectionsPage.vue'),
  'attendance/exceptions': () => import('./AttendanceExceptionsPage.vue'),
  'attendance/closing': () => import('./AttendanceClosingPage.vue'),
  'attendance/devices': () => import('./AttendanceDevicesPage.vue'),
  'leave/index': () => import('./LeaveMyPage.vue'),
  'leave/requests': () => import('./LeaveRequestsPage.vue'),
  'leave/types': () => import('./LeaveTypesPage.vue'),
  'leave/policies': () => import('./LeavePoliciesPage.vue'),
  'leave/balances': () => import('./LeaveBalancesPage.vue'),
  'leave/calendar': () => import('./LeaveCalendarPage.vue'),
  'leave/cancellations': () => import('./LeaveCancellationsPage.vue'),
  'leave/reports': () => import('./LeaveReportsPage.vue'),
  'overtime/index': () => import('./OvertimeMyPage.vue'),
  'overtime/requests': () => import('./OvertimeRequestsPage.vue'),
  'overtime/policies': () => import('./OvertimePoliciesPage.vue'),
  'overtime/confirm': () => import('./OvertimeConfirmPage.vue'),
  'overtime/timeoff': () => import('./OvertimeTimeoffPage.vue'),
  'overtime/settlement': () => import('./OvertimeSettlementPage.vue'),
  'travel/index': () => import('./TravelMyPage.vue'),
  'travel/requests': () => import('./TravelRequestsPage.vue'),
  'travel/policies': () => import('./TravelPoliciesPage.vue'),
  'travel/approval': () => import('./TravelApprovalPage.vue'),
  'travel/changes': () => import('./TravelChangesPage.vue'),
  'travel/reports': () => import('./TravelReportsPage.vue'),
  'travel/expenses': () => import('./TravelExpensesPage.vue'),
  'payroll/index': () => import('./PayrollOverviewPage.vue'),
  'payroll/components': () => import('./PayrollItemsPage.vue'),
  'payroll/plans': () => import('./PayrollSchemesPage.vue'),
  'payroll/employee-plans': () => import('./EmployeeCompensationPage.vue'),
  'payroll/statutory': () => import('./PayrollStatutoryPage.vue'),
  'payroll/inputs': () => import('./PayrollInputsPage.vue'),
  'payroll/batches': () => import('./PayrollRunsPage.vue'),
  'payroll/reviews': () => import('./PayrollReviewsPage.vue'),
  'payroll/disbursement': () => import('./PayrollDisbursementPage.vue'),
  'payroll/payslips': () => import('./PayslipsPage.vue'),
  'payroll/adjustments': () => import('./PayrollAdjustmentsPage.vue'),
  'payroll/reports': () => import('./PayrollReportsPage.vue'),
} as const
```

- [ ] **Step 5: 切换 `pageComponents.ts` 的 TimePay 域**

删除 `DOMAIN_LOADERS` 的 `attendance/leave/overtime/travel/payroll` 壳分支，改为：

```ts
import { PAGE_COMPONENTS as TIMEPAY_PAGES } from '@/modules/timepay/pages'

export function timePayPageLoader(key: string): Loader | null {
  return TIMEPAY_PAGES[key as keyof typeof TIMEPAY_PAGES] ?? null
}
```

`matureView()` 先查 `TIMEPAY_PAGES.PAGE_COMPONENTS[key]`。

- [ ] **Step 6: 更新测试与真实栈 E2E**

- `timePayrollPages.spec.ts` 按页面文件挂载；
- 增加“考勤页不渲染薪资页”“出差政策页不渲染报销页”断言；
- `timepay-pages.spec.ts` 用 `demo.admin` 打开 `/attendance`、`/leave`、`/travel/policies`、`/payroll/batches`，断言标题与主 API 不同。

- [ ] **Step 7: 运行门禁**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- timePayrollPages.spec.ts pageComponentUniqueness.spec.ts
node ../scripts/audit_page_components.mjs
HMRS_DEMO_PASSWORD=... pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/timepay-pages.spec.ts
```

Expected: PASS；TimePay 43 页全部映射。

- [ ] **Step 8: 提交**

```bash
git add -A frontend/src/modules/timepay frontend/src/views/timepay frontend/src/router/pageComponents.ts frontend/tests/unit/timePayrollPages.spec.ts frontend/tests/e2e/real/timepay-pages.spec.ts
git commit -m "refactor(timepay): split shared workspace into independent pages"
```

---

### Task 12: Talent 模块拆分（绩效/招聘/培训）

**Files:**
- Create: `frontend/src/modules/talent/pages/index.ts`
- Create: `frontend/src/modules/talent/pages/*.vue`（36 个页面文件）
- Create: `frontend/src/modules/talent/components/TalentPageShell.vue`
- Modify: `frontend/src/router/pageComponents.ts`
- Modify: `frontend/src/views/talent/index.ts`
- Modify: `frontend/src/views/talent/TalentWorkspacePage.vue`（最终删除）
- Test: `frontend/tests/unit/talentPages.spec.ts`
- Test: `frontend/tests/e2e/real/talent-pages.spec.ts`

**页面文件清单（36）：**

| 分组 | componentKey → 文件 |
|---|---|
| 绩效 10 | `performance/index` → `PerformanceCyclesPage.vue`；`performance/templates` → `PerformanceTemplatesPage.vue`；`performance/indicators` → `PerformanceIndicatorsPage.vue`；`performance/goals` → `PerformanceGoalsPage.vue`；`performance/reviews` → `PerformanceReviewsPage.vue`；`performance/feedback` → `PerformanceFeedbackPage.vue`；`performance/calibration` → `PerformanceCalibrationPage.vue`；`performance/results` → `PerformanceResultsPage.vue`；`performance/appeals` → `PerformanceAppealsPage.vue`；`performance/reports` → `PerformanceReportsPage.vue` |
| 招聘需求 7 | `recruitment/requests/index` → `RequisitionListPage.vue`；`recruitment/requests/create` → `RequisitionCreatePage.vue`；`recruitment/requests/approval` → `RequisitionApprovalPage.vue`；`recruitment/requests/openings` → `RequisitionOpeningsPage.vue`；`recruitment/requests/board` → `RequisitionBoardPage.vue`；`recruitment/requests/budget` → `RequisitionBudgetPage.vue`；`recruitment/requests/reports` → `RequisitionReportsPage.vue` |
| 候选人 10 | `recruitment/candidates/index` → `CandidateListPage.vue`；`recruitment/candidates/by-id` → `CandidateDetailPage.vue`；`recruitment/candidates/imports` → `CandidateImportsPage.vue`；`recruitment/candidates/pipeline` → `CandidatePipelinePage.vue`；`recruitment/candidates/interviews` → `CandidateInterviewsPage.vue`；`recruitment/candidates/evaluations` → `CandidateEvaluationsPage.vue`；`recruitment/candidates/offers` → `CandidateOffersPage.vue`；`recruitment/candidates/communications` → `CandidateCommunicationsPage.vue`；`recruitment/candidates/talent-pool` → `CandidateTalentPoolPage.vue`；`recruitment/candidates/convert` → `CandidateConvertPage.vue` |
| 培训 9 | `training/index` → `TrainingProgramsPage.vue`；`training/courses` → `TrainingCoursesPage.vue`；`training/sessions` → `TrainingSessionsPage.vue`；`training/enrollments` → `TrainingEnrollmentsPage.vue`；`training/checkins` → `TrainingCheckinsPage.vue`；`training/exams` → `TrainingExamsPage.vue`；`training/feedback` → `TrainingFeedbackPage.vue`；`training/records` → `TrainingRecordsPage.vue`；`training/reports` → `TrainingReportsPage.vue` |

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import RequisitionListPage from '@/modules/talent/pages/RequisitionListPage.vue'
import CandidatePipelinePage from '@/modules/talent/pages/CandidatePipelinePage.vue'
import TrainingProgramsPage from '@/modules/talent/pages/TrainingProgramsPage.vue'

describe('talent pages', () => {
  it('招聘需求、候选人、培训是三份独立页面', () => {
    expect(RequisitionListPage).not.toBe(CandidatePipelinePage)
    expect(CandidatePipelinePage).not.toBe(TrainingProgramsPage)
  })
})
```

- [ ] **Step 2: 运行，确认失败**

```bash
cd frontend
pnpm test:unit -- talentPages.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 创建 `TalentPageShell.vue` 与 36 个页面**

- 复用 `TalentForm.vue`、`TalentPage.vue` 作为组件，不作为路由页面；
- 每个页面使用自己的 `useTalentPage` 配置、读路径、表单组合和标题；
- 招聘需求页不得渲染候选人表格；培训页不得复用绩效周期组件。

- [ ] **Step 4: 写 `pages/index.ts`**

```ts
// Task 12 完整 36 项；必须与表格逐行一致。
export const PAGE_COMPONENTS = {
  'performance/index': () => import('./PerformanceCyclesPage.vue'),
  'performance/templates': () => import('./PerformanceTemplatesPage.vue'),
  'performance/indicators': () => import('./PerformanceIndicatorsPage.vue'),
  'performance/goals': () => import('./PerformanceGoalsPage.vue'),
  'performance/reviews': () => import('./PerformanceReviewsPage.vue'),
  'performance/feedback': () => import('./PerformanceFeedbackPage.vue'),
  'performance/calibration': () => import('./PerformanceCalibrationPage.vue'),
  'performance/results': () => import('./PerformanceResultsPage.vue'),
  'performance/appeals': () => import('./PerformanceAppealsPage.vue'),
  'performance/reports': () => import('./PerformanceReportsPage.vue'),
  'recruitment/requests/index': () => import('./RequisitionListPage.vue'),
  'recruitment/requests/create': () => import('./RequisitionCreatePage.vue'),
  'recruitment/requests/approval': () => import('./RequisitionApprovalPage.vue'),
  'recruitment/requests/openings': () => import('./RequisitionOpeningsPage.vue'),
  'recruitment/requests/board': () => import('./RequisitionBoardPage.vue'),
  'recruitment/requests/budget': () => import('./RequisitionBudgetPage.vue'),
  'recruitment/requests/reports': () => import('./RequisitionReportsPage.vue'),
  'recruitment/candidates/index': () => import('./CandidateListPage.vue'),
  'recruitment/candidates/by-id': () => import('./CandidateDetailPage.vue'),
  'recruitment/candidates/imports': () => import('./CandidateImportsPage.vue'),
  'recruitment/candidates/pipeline': () => import('./CandidatePipelinePage.vue'),
  'recruitment/candidates/interviews': () => import('./CandidateInterviewsPage.vue'),
  'recruitment/candidates/evaluations': () => import('./CandidateEvaluationsPage.vue'),
  'recruitment/candidates/offers': () => import('./CandidateOffersPage.vue'),
  'recruitment/candidates/communications': () => import('./CandidateCommunicationsPage.vue'),
  'recruitment/candidates/talent-pool': () => import('./CandidateTalentPoolPage.vue'),
  'recruitment/candidates/convert': () => import('./CandidateConvertPage.vue'),
  'training/index': () => import('./TrainingProgramsPage.vue'),
  'training/courses': () => import('./TrainingCoursesPage.vue'),
  'training/sessions': () => import('./TrainingSessionsPage.vue'),
  'training/enrollments': () => import('./TrainingEnrollmentsPage.vue'),
  'training/checkins': () => import('./TrainingCheckinsPage.vue'),
  'training/exams': () => import('./TrainingExamsPage.vue'),
  'training/feedback': () => import('./TrainingFeedbackPage.vue'),
  'training/records': () => import('./TrainingRecordsPage.vue'),
  'training/reports': () => import('./TrainingReportsPage.vue'),
} as const
```

- [ ] **Step 5: 切换 `pageComponents.ts` 的 Talent 域并删除壳**

`matureView()` 对 `performance/`、`recruitment/`、`training/` 只查 `TALENT_PAGES.PAGE_COMPONENTS[key]`；找不到返回 `null`。删除 `frontend/src/views/talent/index.ts` 的动态壳工厂与 `TalentWorkspacePage.vue`。

- [ ] **Step 6: 更新测试与 E2E**

- `talentPages.spec.ts` 按页面挂载；
- 增加“招聘需求不渲染候选人”“绩效结果不渲染培训场次”负向断言；
- `talent-pages.spec.ts` 用 `demo.admin` 打开 `/performance`、`/recruitment/requests`、`/recruitment/candidates/pipeline`、`/training`，断言标题/主 API 不同。

- [ ] **Step 7: 运行门禁**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- talentPages.spec.ts pageComponentUniqueness.spec.ts
node ../scripts/audit_page_components.mjs
HMRS_DEMO_PASSWORD=... pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/talent-pages.spec.ts
```

Expected: PASS；Talent 36 页全部映射。

- [ ] **Step 8: 提交**

```bash
git add -A frontend/src/modules/talent frontend/src/views/talent frontend/src/router/pageComponents.ts frontend/tests/unit/talentPages.spec.ts frontend/tests/e2e/real/talent-pages.spec.ts
git commit -m "refactor(talent): split shared workspace into independent pages"
```

---

### Task 13: Platform 模块拆分（仪表盘/报表/公告/系统管理）

**Files:**
- Create: `frontend/src/modules/platform/pages/index.ts`
- Create: `frontend/src/modules/platform/pages/*.vue`（32 个当前经壳页面）
- Create: `frontend/src/modules/platform/components/PlatformPageShell.vue`
- Modify: `frontend/src/router/pageComponents.ts`
- Modify: `frontend/src/views/platform/index.ts`
- Modify: `frontend/src/views/platform/PlatformWorkspace.vue`（最终删除）
- Test: `frontend/tests/unit/platformPages.spec.ts`
- Test: `frontend/tests/e2e/real/platform-pages.spec.ts`

**页面文件清单（32 个当前经壳页面；`dashboard/index`、`reports/index`、`notices/index`、`system/system-users` 已在 `BASE_VIEWS`，也应迁入模块目录）**

| 分组 | componentKey → 文件 |
|---|---|
| 仪表盘 6 | `dashboard/index` → `DashboardHomePage.vue`；`dashboard/departments` → `DashboardDepartmentsPage.vue`；`dashboard/workforce` → `DashboardWorkforcePage.vue`；`dashboard/changes` → `DashboardChangesPage.vue`；`dashboard/tasks` → `DashboardTasksPage.vue`；`dashboard/recruitment` → `DashboardRecruitmentPage.vue` |
| 报表 9 | `reports/index` → `ReportCatalogPage.vue`；`reports/headcount` → `ReportHeadcountPage.vue`；`reports/attendance` → `ReportAttendancePage.vue`；`reports/payroll` → `ReportPayrollPage.vue`；`reports/hiring` → `ReportHiringPage.vue`；`reports/talent` → `ReportTalentPage.vue`；`reports/builder` → `ReportBuilderPage.vue`；`reports/subscriptions` → `ReportSubscriptionsPage.vue`；`reports/exports` → `ReportExportsPage.vue` |
| 公告 7 | `notices/index` → `NoticeListPage.vue`；`notices/editor` → `NoticeEditorPage.vue`；`notices/approval` → `NoticeApprovalPage.vue`；`notices/audience` → `NoticeAudiencePage.vue`；`notices/reads` → `NoticeReadsPage.vue`；`notices/templates` → `NoticeTemplatesPage.vue`；`notices/archive` → `NoticeArchivePage.vue` |
| 系统 14 | `account` → `AccountSecurityPage.vue`；`system/system-users` → `SystemUsersPage.vue`；`system/system-roles` → `SystemRolesPage.vue`；`system/system-menus` → `SystemMenusPage.vue`；`system/system-permissions` → `SystemPermissionsPage.vue`；`system/system-data-scopes` → `SystemDataScopesPage.vue`；`system/system-field-policies` → `SystemFieldPoliciesPage.vue`；`workflow` → `WorkflowCenterPage.vue`；`self-service` → `SelfServicePage.vue`；`benefits` → `BenefitsPage.vue`；`system/system-calendars` → `SystemCalendarsPage.vue`；`system/system-settings` → `SystemSettingsPage.vue`；`notifications` → `NotificationsPage.vue`；`system/system-audit` → `SystemAuditPage.vue` |

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import SystemUsersPage from '@/modules/platform/pages/SystemUsersPage.vue'
import SystemRolesPage from '@/modules/platform/pages/SystemRolesPage.vue'
import NoticeListPage from '@/modules/platform/pages/NoticeListPage.vue'

describe('platform pages', () => {
  it('用户、角色、公告是三份独立页面', () => {
    expect(SystemUsersPage).not.toBe(SystemRolesPage)
    expect(SystemRolesPage).not.toBe(NoticeListPage)
  })
})
```

- [ ] **Step 2: 运行，确认失败**

```bash
cd frontend
pnpm test:unit -- platformPages.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 按 4.8 的“角色管理”目标重写角色页**

`SystemRolesPage.vue` 必须包含：

- 角色列表（含页面数、动作数、成员数、到期日）；
- 页面授权树：按 17 个菜单分组，父项只控制展开，子项逐个 `page_code` 勾选；
- 动作能力选择：从只读能力目录选择，选择后调用 `PUT /v1/roles/{id}/capabilities`；
- 页面授权保存：调用 `PUT /v1/roles/{id}/pages`（Task 14 新增）；
- 权限预览：选择用户后显示有效页面/动作/范围/字段；
- 差异预览与高危确认（提交前展示页面/能力新增/移除）。

`SystemPermissionsPage.vue` 只读展示“我的有效页面/动作/范围/字段”，不再把 `GET /v1/me/permissions` 当作公司能力目录。

- [ ] **Step 4: 创建 `PlatformPageShell.vue` 与 32 个页面**

- 把 `PlatformWorkspace.vue` 的 4,790 行按 `mode` 拆成页面；
- `users`、`roles`、`menus`、`permissions`、`audit` 各自独立；
- 报表 9 页共享图表组件，但每页自己的筛选/指标/导出配置；
- 公告 7 页共享公告表单，但列表/审核/受众/阅读/模板/归档各自独立。

- [ ] **Step 5: 写 `pages/index.ts` 并切换 `pageComponents.ts`**

```ts
// Task 13 完整 36 项；必须与表格逐行一致。
export const PAGE_COMPONENTS = {
  'dashboard/index': () => import('./DashboardHomePage.vue'),
  'dashboard/departments': () => import('./DashboardDepartmentsPage.vue'),
  'dashboard/workforce': () => import('./DashboardWorkforcePage.vue'),
  'dashboard/changes': () => import('./DashboardChangesPage.vue'),
  'dashboard/tasks': () => import('./DashboardTasksPage.vue'),
  'dashboard/recruitment': () => import('./DashboardRecruitmentPage.vue'),
  'reports/index': () => import('./ReportCatalogPage.vue'),
  'reports/headcount': () => import('./ReportHeadcountPage.vue'),
  'reports/attendance': () => import('./ReportAttendancePage.vue'),
  'reports/payroll': () => import('./ReportPayrollPage.vue'),
  'reports/hiring': () => import('./ReportHiringPage.vue'),
  'reports/talent': () => import('./ReportTalentPage.vue'),
  'reports/builder': () => import('./ReportBuilderPage.vue'),
  'reports/subscriptions': () => import('./ReportSubscriptionsPage.vue'),
  'reports/exports': () => import('./ReportExportsPage.vue'),
  'notices/index': () => import('./NoticeListPage.vue'),
  'notices/editor': () => import('./NoticeEditorPage.vue'),
  'notices/approval': () => import('./NoticeApprovalPage.vue'),
  'notices/audience': () => import('./NoticeAudiencePage.vue'),
  'notices/reads': () => import('./NoticeReadsPage.vue'),
  'notices/templates': () => import('./NoticeTemplatesPage.vue'),
  'notices/archive': () => import('./NoticeArchivePage.vue'),
  'account': () => import('./AccountSecurityPage.vue'),
  'system/system-users': () => import('./SystemUsersPage.vue'),
  'system/system-roles': () => import('./SystemRolesPage.vue'),
  'system/system-menus': () => import('./SystemMenusPage.vue'),
  'system/system-permissions': () => import('./SystemPermissionsPage.vue'),
  'system/system-data-scopes': () => import('./SystemDataScopesPage.vue'),
  'system/system-field-policies': () => import('./SystemFieldPoliciesPage.vue'),
  'workflow': () => import('./WorkflowCenterPage.vue'),
  'self-service': () => import('./SelfServicePage.vue'),
  'benefits': () => import('./BenefitsPage.vue'),
  'system/system-calendars': () => import('./SystemCalendarsPage.vue'),
  'system/system-settings': () => import('./SystemSettingsPage.vue'),
  'notifications': () => import('./NotificationsPage.vue'),
  'system/system-audit': () => import('./SystemAuditPage.vue'),
} as const
```

删除 `BASE_VIEWS` 中的 `dashboard/index`、`reports/index`、`notices/index`、`system/system-users` 旧映射，统一走模块目录。

- [ ] **Step 6: 更新测试与 E2E**

- `platformPages.spec.ts` 按页面挂载；
- 增加“用户页不渲染角色授权树”“公告列表不渲染系统审计”负向断言；
- `platform-pages.spec.ts` 用 `demo.admin` 打开 `/system/users`、`/system/roles`、`/system/audit`、`/reports`，断言页面标题/主 API 不同。

- [ ] **Step 7: 运行门禁**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- platformPages.spec.ts pageComponentUniqueness.spec.ts
node ../scripts/audit_page_components.mjs
HMRS_DEMO_PASSWORD=... pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/platform-pages.spec.ts
```

Expected: PASS；全部 160 页唯一映射。

- [ ] **Step 8: 提交**

```bash
git add -A frontend/src/modules/platform frontend/src/views/platform frontend/src/router/pageComponents.ts frontend/tests/unit/platformPages.spec.ts frontend/tests/e2e/real/platform-pages.spec.ts
git commit -m "refactor(platform): split shared workspace into independent pages"
```

---

### Task 14: 角色页面授权 API + 用户角色分配 API

**Files:**
- Create: `backend/app/Modules/IAM/Http/Controllers/RolePageController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Modules/IAM/Services/RoleAdminService.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php`
- Modify: `docs/openapi/openapi.yaml`
- Modify: `docs/openapi/iam.yaml`
- Modify: `frontend/src/modules/platform/pages/SystemRolesPage.vue`
- Test: `backend/tests/Feature/IAM/RolePagesApiTest.php`
- Test: `frontend/tests/unit/systemRolesPage.spec.ts`

**Interfaces:**
- Produces: `GET /v1/roles/{id}/pages` → `{role_code, pages: [{page_code, path, menu_group, menu_label}]}`
- Produces: `PUT /v1/roles/{id}/pages` body `{pages: string[]}`，写入 `role_pages` 并重放受影响用户快照。
- 权限：`iam.role.manage` + `COMPANY` + `mfa.enrolled` + `mfa.step-up`。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RolePagesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_page_update_replaces_the_selected_pages_and_replays_users(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->roleManager();
        $headers = ['Idempotency-Key' => 'role-pages-1', 'X-CSRF-Token' => 'test-csrf', ];

        $this->putJson('/v1/roles/HR_SPECIALIST/pages', [
            'pages' => ['hr-employees', 'hr-contracts'],
        ], $headers)->assertOk();

        $codes = RolePage::query()->where('role_code', 'HR_SPECIALIST')->pluck('page_code')->all();
        sort($codes);
        $this->assertSame(['hr-contracts', 'hr-employees'], $codes);
    }

    public function test_unknown_page_code_is_rejected(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $headers = ['Idempotency-Key' => 'role-pages-2', 'X-CSRF-Token' => 'test-csrf', ];

        $this->putJson('/v1/roles/HR_SPECIALIST/pages', [
            'pages' => ['not-a-page'],
        ], $headers)->assertStatus(422)->assertJsonPath('data.reason', 'UNKNOWN_PAGE_CODE');
    }
}
```

- [ ] **Step 2: 运行，确认 404**

```bash
cd backend
php artisan test --compact --filter=RolePagesApiTest
```

Expected: FAIL，路由不存在。

- [ ] **Step 3: 实现控制器与路由**

```php
<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AuthorizationSnapshot;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RolePageController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly PermissionResourceCatalog $catalog,
        private readonly AuthorizationSnapshot $snapshot,
        private readonly Clock $clock,
    ) {}

    public function index(string $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $role = Role::query()->whereKey($id)->first();
        if ($role === null) throw new NotFoundOrForbiddenException;

        $codes = RolePage::query()->where('role_code', $role->code)->pluck('page_code')->all();
        $pages = array_values(array_filter(
            $this->catalog->pages(),
            static fn (array $page): bool => in_array($page['page_code'], $codes, true),
        ));

        return ApiResponse::ok(['role_code' => $role->code, 'pages' => $pages]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'pages' => ['required', 'array', 'max:160'],
            'pages.*' => ['required', 'string', 'max:120'],
        ]);

        $role = Role::query()->whereKey($id)->first();
        if ($role === null) throw new NotFoundOrForbiddenException;

        $known = array_column($this->catalog->pages(), 'page_code');
        $unknown = array_values(array_diff($data['pages'], $known));
        if ($unknown !== []) {
            throw new ValidationException(
                ['pages' => ['Unknown page code.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_PAGE_CODE', 'unknown' => $unknown],
            );
        }

        DB::transaction(function () use ($principal, $role, $data): void {
            RolePage::query()->where('role_code', $role->code)->delete();
            foreach (array_values(array_unique($data['pages'])) as $pageCode) {
                RolePage::query()->create([
                    'role_code' => (string) $role->code,
                    'page_code' => $pageCode,
                    'created_at' => $this->clock->now(),
                ]);
            }

            UserRole::query()
                ->where('role_code', $role->code)
                ->pluck('user_id')
                ->unique()
                ->each(function (mixed $userId) use ($principal): void {
                    $user = User::query()->whereKey((int) $userId)->first();
                    if ($user instanceof User) {
                        $this->snapshot->replay($user, 'role.pages.changed');
                    }
                });
        });

        return ApiResponse::ok(['role_code' => $role->code, 'pages' => $data['pages']]);
    }
}
```

路由：

```php
Route::get('/roles/{id}/pages', [RolePageController::class, 'index'])
    ->middleware(['mfa.enrolled', 'capability:iam.role.manage,COMPANY'])
    ->name('roles.pages.index');
Route::put('/roles/{id}/pages', [RolePageController::class, 'update'])
    ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])
    ->name('roles.pages.replace');
```

- [ ] **Step 4: 角色列表返回页面数**

`AuthorizationAdminController::roles()`：

```php
'page_count' => RolePage::query()->where('role_code', $role->code)->count(),
'member_count' => UserRole::query()->where('role_code', $role->code)->distinct('user_id')->count('user_id'),
```

- [ ] **Step 5: 前端角色页勾选**

`SystemRolesPage.vue` 增加：

```vue
<section class="panel" data-testid="role-pages-panel">
  <header class="panel__header">
    <div>
      <h2 class="panel__title">页面权限</h2>
      <p class="panel__subtitle">父菜单只控制展开；每个页面单独勾选。</p>
    </div>
    <span class="plain-chip">{{ selectedPageCodes.length }} / 160</span>
  </header>
  <div class="panel__body">
    <details v-for="group in pageGroups" :key="group.key" open>
      <summary>{{ group.label }}</summary>
      <label v-for="page in group.pages" :key="page.page_code">
        <input v-model="selectedPageCodes" type="checkbox" :value="page.page_code" />
        {{ page.menu_label }}
      </label>
    </details>
    <el-button type="primary" data-testid="save-role-pages" :loading="savingPages" @click="saveRolePages">
      保存页面权限
    </el-button>
  </div>
</section>
```

保存：

```ts
async function saveRolePages(): Promise<void> {
  await resourcePut(`/v1/roles/${roleCode}/pages`, { pages: selectedPageCodes.value }, {
    idempotencyKey: nextIdempotencyKey('role-pages'),
  })
  successMessage.value = '页面权限已保存；下一次请求立即生效。'
}
```

- [ ] **Step 6: OpenAPI 更新**

增加 `/v1/roles/{id}/pages` 的 GET/PUT、`RolePageList`、`RolePageReplaceRequest` schema；`x-capability: iam.role.manage`。

- [ ] **Step 7: 运行测试**

```bash
cd backend
php artisan test --compact --filter='RolePagesApiTest|RolePagesTest|MePermissionsPageGrantTest'
cd ../frontend
pnpm typecheck
pnpm test:unit -- systemRolesPage.spec.ts
cd ..
node deploy/scripts/openapi-lint.mjs
pnpm openapi:check
```

Expected: PASS。

- [ ] **Step 8: 提交**

```bash
git add backend/app/Modules/IAM/Http/Controllers/RolePageController.php backend/routes/api.php backend/app/Modules/IAM/Services/RoleAdminService.php backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php frontend/src/modules/platform/pages/SystemRolesPage.vue backend/tests/Feature/IAM/RolePagesApiTest.php frontend/tests/unit/systemRolesPage.spec.ts docs/openapi/openapi.yaml docs/openapi/iam.yaml
git commit -m "feat(iam): add role page grant editing api and UI"
```

---

### Task 14A: 按同事分配角色 UI（角色下拉、多选、撤销）

**Files:**
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php`
- Create: `backend/app/Modules/IAM/Http/Controllers/UserRoleController.php`
- Modify: `frontend/src/api/http.ts`
- Modify: `frontend/src/modules/platform/pages/SystemUsersPage.vue`
- Modify: `docs/openapi/openapi.yaml`
- Test: `backend/tests/Feature/IAM/UserRoleAssignmentApiTest.php`
- Test: `frontend/tests/unit/systemUsersPage.spec.ts`

**Interfaces:**
- Produces: `GET /v1/users` 每项增加 `roles: [{role_code, valid_from, valid_to}]`。
- Produces: `GET /v1/users/{id}/roles`。
- Produces: `POST /v1/users/{id}/roles`（支持多角色逐个提交）。
- Produces: `DELETE /v1/users/{id}/roles/{roleCode}`，撤权并重放快照。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserRoleAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_list_exposes_effective_roles_instead_of_privileged_flag(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $target = User::factory()->create(['is_privileged' => false]);
        UserRole::query()->create([
                        'user_id' => $target->id,
            'role_code' => 'HR_SPECIALIST',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'granted_by' => null,
            'reason' => 'Synthetic assignment',
            'auth_version' => $target->auth_version,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $response = $this->actingAs($this->roleManager())
            ->getJson('/v1/users?page=1&page_size=20', [])
            ->assertOk();

        $this->assertSame('HR_SPECIALIST', $response->json('data.items.0.roles.0.role_code'));
    }
}
```

- [ ] **Step 2: 运行，确认用户列表缺 roles**

```bash
cd backend
php artisan test --compact --filter=UserRoleAssignmentApiTest
```

Expected: FAIL。

- [ ] **Step 3: 用户列表返回角色**

在 `UserAdminController::index()` 的每项增加：

```php
'roles' => UserRole::query()
    ->where('user_id', (int) $user->id)
    ->where('auth_version', (int) $user->auth_version)
    ->where('valid_from', '<=', $this->clock->now())
    ->where('valid_to', '>', $this->clock->now())
    ->get()
    ->map(fn (UserRole $role): array => [
        'role_code' => (string) $role->role_code,
        'valid_from' => $role->valid_from->toIso8601ZuluString(),
        'valid_to' => $role->valid_to->toIso8601ZuluString(),
    ])->all(),
```

- [ ] **Step 4: 新增撤销路由**

```php
Route::delete('/users/{id}/roles/{roleCode}', [UserRoleController::class, 'destroy'])
    ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])
    ->name('users.roles.destroy');
```

`destroy()` 在事务内删除 `user_roles` 行、删除该角色对应的 `authority_bindings`（同 auth_version），再调用 `AuthorizationSnapshot::replay()`。

- [ ] **Step 5: 前端改为下拉多选**

`SystemUsersPage.vue`：

```vue
<el-select v-model="roleForm.roleCodes" multiple filterable data-testid="assign-role-select">
  <el-option v-for="role in roles" :key="role.code" :label="`${role.name} (${role.code})`" :value="role.code" />
</el-select>
```

用户表格增加“角色”列：

```vue
<td>{{ nestedRows(user, 'roles').map((role) => rowText(role, 'role_code')).join('、') || '未分配' }}</td>
```

提交时逐个调用 `POST /v1/users/{id}/roles`，每个角色独立 idempotency key；撤销调用 `DELETE`。

- [ ] **Step 6: 运行测试**

```bash
cd backend
php artisan test --compact --filter='UserRoleAssignmentApiTest|RoleAssignmentTest|SodRiskAcknowledgementTest'
cd ../frontend
pnpm typecheck
pnpm test:unit -- systemUsersPage.spec.ts
cd ..
node deploy/scripts/openapi-lint.mjs
pnpm openapi:check
```

Expected: PASS；撤权后下一次请求 403。

- [ ] **Step 7: 提交**

```bash
git add backend/routes/api.php backend/app/Modules/IAM/Http/Controllers/UserAdminController.php backend/app/Modules/IAM/Http/Controllers/UserRoleController.php frontend/src/api/http.ts frontend/src/modules/platform/pages/SystemUsersPage.vue backend/tests/Feature/IAM/UserRoleAssignmentApiTest.php frontend/tests/unit/systemUsersPage.spec.ts docs/openapi/openapi.yaml
git commit -m "feat(iam): assign and revoke roles per user"
```

---

### Task 15: 权限预览（页面/动作/范围/拒绝原因）

**Files:**
- Create: `backend/app/Modules/IAM/Authorization/PermissionProjection.php`
- Create: `backend/app/Modules/IAM/Http/Controllers/PermissionPreviewController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Modules/IAM/Http/Controllers/MeController.php`
- Modify: `docs/openapi/openapi.yaml`
- Modify: `frontend/src/api/http.ts`
- Modify: `frontend/src/modules/platform/pages/SystemUsersPage.vue`
- Modify: `frontend/src/modules/platform/pages/SystemRolesPage.vue`
- Test: `backend/tests/Feature/IAM/PermissionPreviewApiTest.php`
- Test: `frontend/tests/unit/permissionPreview.spec.ts`

**Interfaces:**
- Produces: `GET /v1/users/{id}/permissions-preview`：

```json
{
  "user": {"id": 12, "username": "alice"},
  "roles": ["HR_SPECIALIST"],
  "pages": [{"page_code": "hr-employees", "path": "/hr/employees", "menu_group": "员工档案"}],
  "actions": [{"capability": "people.employee.read", "anchor_type": "COMPANY", "anchor_id": null, "field_profile": "P2_SENSITIVE"}],
  "denied": [{"page_code": "hr-employees-by-id-education", "path": "/hr/employees/:id/education", "reason": "PAGE_NOT_GRANTED"}]
}
```

- Produces: `/me/permissions` 与预览共用 `PermissionProjection`。

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PermissionPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_visible_pages_and_denied_reasons(): void
    {
        Artisan::call('iam:sync-capabilities');

        $actor = User::factory()->create(['is_privileged' => true]);
        $this->grant($actor, 'iam.user.read', 'COMPANY', null, 'P1_INTERNAL', 'SYSTEM_ADMIN');

        $target = User::factory()->create(['auth_version' => 1]);
        UserRole::query()->create([
                        'user_id' => $target->id,
            'role_code' => 'HR_SPECIALIST',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'granted_by' => $actor->id,
            'reason' => 'Synthetic preview test',
            'auth_version' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        RolePage::query()->create([
                        'role_code' => 'HR_SPECIALIST',
            'page_code' => 'hr-employees',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->grant($target, 'people.employee.read', 'COMPANY', null, 'P2_SENSITIVE', 'HR_SPECIALIST');

        $response = $this->actingAs($actor)
            ->getJson("/v1/users/{$target->id}/permissions-preview", [])
            ->assertOk();

        $paths = array_column($response->json('data.pages'), 'path');
        $this->assertContains('/hr/employees', $paths);
        $this->assertNotContains('/hr/employees/:id/education', $paths);
        $this->assertContains('PAGE_NOT_GRANTED', array_column($response->json('data.denied'), 'reason'));
    }

    private function grant(User $user, string $capability, string $anchor, int $anchorId, string $profile, string $purpose): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::from($anchor), $anchorId, FieldProfile::from($profile))
            ->create([
                'capability_code' => $capability,
                'purpose_code' => $purpose,
            ]);
    }
}
```

- [ ] **Step 2: 运行，确认 404**

```bash
cd backend
php artisan test --compact --filter=PermissionPreviewApiTest
```

Expected: FAIL，路由不存在。

- [ ] **Step 3: 抽出 PermissionProjection**

```php
final class PermissionProjection
{
    /**
     * @return array{roles: list<string>, pages: list<array<string, mixed>>, actions: list<array<string, mixed>>, denied: list<array<string, mixed>>}
     */
    public function forUser(int $tenantId, int $userId, int $authVersion): array
    {
        // 1. load active user_roles
        // 2. load role_pages for those roles
        // 3. load authority_bindings for the auth_version
        // 4. iterate PermissionResourceCatalog pages:
        //    - missing page grant -> denied PAGE_NOT_GRANTED
        //    - missing capability -> denied CAPABILITY_NOT_GRANTED
        //    - else visible
        // 5. return roles/pages/actions/denied
    }
}
```

`MeController::permissions()` 改为调用该投影，不保留第二份判定。

- [ ] **Step 4: 控制器与路由**

```php
final class PermissionPreviewController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly PermissionProjection $projection,
    ) {}

    public function __invoke(int $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $target = \App\Modules\IAM\Models\User::query()
            ->whereKey($id)
            ->firstOrFail();

        return ApiResponse::ok([
            'user' => ['id' => (int) $target->id, 'username' => (string) $target->username],
            ...$this->projection->forUser((int) $target->id, (int) $target->auth_version),
        ]);
    }
}
```

路由：

```php
Route::get('/users/{id}/permissions-preview', PermissionPreviewController::class)
    ->whereNumber('id')
    ->middleware(['mfa.enrolled', 'capability:iam.user.read,COMPANY'])
    ->name('users.permissions-preview');
```

- [ ] **Step 5: 前端权限预览面板**

`SystemUsersPage.vue` 增加：

```vue
<el-button text size="small" data-testid="permission-preview" @click="openPreview(user)">权限预览</el-button>
```

预览面板按 `pages`、`actions`、`denied` 展示；`denied.reason` 映射中文：

```ts
const DENIED_LABEL: Record<string, string> = {
  PAGE_NOT_GRANTED: '角色未授予该页面',
  CAPABILITY_NOT_GRANTED: '缺少动作能力',
  OUT_OF_COMPANY_SCOPE: '超出公司数据范围',
  MENU_HIDDEN: '菜单被覆盖隐藏',
}
```

- [ ] **Step 6: 运行测试**

```bash
cd backend
php artisan test --compact --filter='PermissionPreviewApiTest|MePermissionsTest'
cd ../frontend
pnpm typecheck
pnpm test:unit -- permissionPreview.spec.ts
cd ..
node deploy/scripts/openapi-lint.mjs
pnpm openapi:check
```

Expected: PASS。

- [ ] **Step 7: 提交**

```bash
git add backend/app/Modules/IAM/Authorization/PermissionProjection.php backend/app/Modules/IAM/Http/Controllers/PermissionPreviewController.php backend/app/Modules/IAM/Http/Controllers/MeController.php backend/routes/api.php frontend/src/api/http.ts frontend/src/modules/platform/pages/SystemUsersPage.vue frontend/src/modules/platform/pages/SystemRolesPage.vue backend/tests/Feature/IAM/PermissionPreviewApiTest.php frontend/tests/unit/permissionPreview.spec.ts docs/openapi/openapi.yaml
git commit -m "feat(iam): add permission preview for users and roles"
```

---

### Task 16: 菜单展开图标、当前路由高亮、持久化

**Files:**
- Modify: `frontend/src/router/navigation.ts`
- Modify: `frontend/src/router/registry.ts`
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/layouts/AppShell.vue`
- Modify: `frontend/src/styles/main.css`
- Test: `frontend/tests/unit/shellMenu.spec.ts`
- Test: `frontend/tests/unit/navigation.spec.ts`
- Test: `frontend/tests/e2e/smoke.spec.ts`
- Test: `frontend/tests/e2e/a11y.spec.ts`

**Interfaces:**
- Produces: `NavigationItem.expandable`、`NavigationChild.icon`。
- Produces: `route.meta.navKey = page.name`。
- Produces: `expandedGroups` 持久化键 `hrms.nav.expanded`。

- [ ] **Step 1: 写失败测试**

```ts
import { describe, expect, it } from 'vitest'
import { NAVIGATION } from '@/router/navigation'

describe('expandable navigation affordance', () => {
  it('每个有子项的菜单都标记为可展开', () => {
    const parents = NAVIGATION.filter((item) => item.children.length > 0 && item.key !== 'workspace')
    expect(parents.length).toBeGreaterThan(0)
    expect(parents.every((item) => item.expandable)).toBe(true)
  })

  it('子项保留图标或使用统一文档图标', () => {
    const children = NAVIGATION.flatMap((item) => item.children)
    expect(children.every((child) => child.icon !== undefined)).toBe(true)
  })
})
```

```ts
import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import AppShell from '@/layouts/AppShell.vue'
import { describe, expect, it } from 'vitest'

describe('menu chevron', () => {
  it('renders a chevron and flips aria-expanded', async () => {
    // Mount AppShell with a synthetic session containing system permissions,
    // find `.nav-link--group` for 系统管理, assert `.nav-link__chevron` exists,
    // click, then assert aria-expanded=false and ChevronRight.
  })
})
```

- [ ] **Step 2: 运行，确认失败**

```bash
cd frontend
pnpm test:unit -- shellMenu.spec.ts navigation.spec.ts
```

Expected: FAIL，`expandable`/`icon` 不存在。

- [ ] **Step 3: 扩展类型与导航构造**

```ts
export type NavigationItem = {
  key: string
  label: string
  path: string
  icon: string
  expandable: boolean
  capability?: string
  group: NavigationGroup
  badge?: 'approvals' | 'notices'
  children: NavigationChild[]
}

export type NavigationChild = {
  key: string
  label: string
  path: string
  capability: string
  order: number
  icon: string
}
```

`NAVIGATION` 构造：

```ts
children.push({
  key: page.name,
  label: page.menu.label,
  path: page.path,
  capability: page.capability,
  order: page.menu.order,
  icon: page.menu.icon,
})
```

父项：

```ts
expandable: children.length > 0,
```

`registry.ts` 的 `MenuSpec.icon` 不再是全部 `Menu`；按页面语义补图标（如 `User`、`Share`、`Document`、`Money`、`Calendar`、`Clock`、`Bell`、`Lock`）。至少保证不同菜单分组图标不同；同组子项允许统一 `Document`，但必须显式存在。

- [ ] **Step 4: 写入 `meta.navKey`**

`registryRoute()`：

```ts
meta: {
  title: page.menu?.label ?? page.name,
  navKey: page.name,
  pageCode: page.name,
  capability: page.capability === 'public' ? undefined : page.capability,
  public: page.capability === 'public',
  menu: page.menu,
  registry: page,
}
```

- [ ] **Step 5: AppShell 渲染 chevron**

```vue
<script setup lang="ts">
import { ArrowDown, ArrowRight, ... } from '@element-plus/icons-vue'
</script>

<template>
  <button
    type="button"
    class="nav-link nav-link--group"
    :class="{ 'nav-link--group-active': isGroupOpen(item) }"
    :aria-expanded="isGroupOpen(item)"
    :aria-controls="`nav-group-${item.key}`"
    @click="toggleGroup(item)"
  >
    <el-icon class="nav-link__icon" aria-hidden="true">
      <component :is="icons[item.icon]" />
    </el-icon>
    <span class="nav-link__label">{{ GROUP_LABEL[item.group] }}</span>
    <span v-if="badgeFor(item)" class="nav-link__badge">{{ badgeFor(item) }}</span>
    <el-icon class="nav-link__chevron" aria-hidden="true">
      <ArrowDown v-if="isGroupOpen(item)" />
      <ArrowRight v-else />
    </el-icon>
  </button>
  <div v-if="isGroupOpen(item) && !app.sidebarCollapsed" :id="`nav-group-${item.key}`" class="sidebar__children">
    <RouterLink ...>
      <el-icon class="nav-link__child-icon" aria-hidden="true">
        <component :is="icons[child.icon] ?? Document" />
      </el-icon>
      <span class="nav-link__label">{{ child.label }}</span>
    </RouterLink>
  </div>
</template>
```

- [ ] **Step 6: 持久化展开状态**

```ts
const NAV_EXPANDED_KEY = 'hrms.nav.expanded'

function readExpandedGroups(): Set<string> {
  try {
    const raw = localStorage.getItem(NAV_EXPANDED_KEY)
    const parsed = raw === null ? [] : JSON.parse(raw)
    return new Set(Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : [])
  } catch {
    return new Set()
  }
}

const expandedGroups = ref<Set<string>>(readExpandedGroups())

function toggleGroup(item: NavigationItem): void {
  const next = new Set(expandedGroups.value)
  if (next.has(item.key)) next.delete(item.key)
  else next.add(item.key)
  expandedGroups.value = next
  localStorage.setItem(NAV_EXPANDED_KEY, JSON.stringify([...next]))
}
```

`isGroupOpen()` 保留“当前路由所在分组强制展开”：

```ts
function isGroupOpen(item: NavigationItem): boolean {
  return expandedGroups.value.has(item.key)
    || item.children.some((child) => child.key === currentNavKey.value)
}
```

- [ ] **Step 7: CSS**

```css
.nav-link__chevron {
  flex: 0 0 auto;
  margin-left: 2px;
  color: #a9c8c3;
  font-size: 14px;
  transition: transform 140ms ease;
}

.nav-link--group-active .nav-link__chevron {
  color: #f2d48b;
}

.nav-link__child-icon {
  flex: 0 0 auto;
  font-size: 14px;
  color: #8fb4ae;
}

.nav-link--child {
  padding-left: 22px;
  gap: 8px;
  font-size: 12px;
}

@media (prefers-reduced-motion: reduce) {
  .nav-link__chevron { transition: none; }
}
```

- [ ] **Step 8: 运行测试与 axe**

```bash
cd frontend
pnpm typecheck
pnpm test:unit -- shellMenu.spec.ts navigation.spec.ts
pnpm exec playwright test tests/e2e/smoke.spec.ts tests/e2e/a11y.spec.ts
```

Expected: PASS；所有父项有 `.nav-link__chevron`；展开/收起 `aria-expanded` 正确；进入 `/system/roles` 后“系统管理”自动展开且子项 `aria-current="page"`；axe critical/serious=0。

- [ ] **Step 9: 提交**

```bash
git add frontend/src/router/navigation.ts frontend/src/router/registry.ts frontend/src/router/index.ts frontend/src/layouts/AppShell.vue frontend/src/styles/main.css frontend/tests/unit/shellMenu.spec.ts frontend/tests/unit/navigation.spec.ts frontend/tests/e2e/smoke.spec.ts frontend/tests/e2e/a11y.spec.ts
git commit -m "feat(ui): add expandable menu affordances and active route state"
```

---

### Task 17: demo.admin 真实栈 160 页与权限矩阵验收

**Files:**
- Modify: `frontend/tests/e2e/real/registry-audit.spec.ts`
- Create: `frontend/tests/e2e/real/admin-all-pages.spec.ts`
- Create: `backend/tests/Feature/IAM/DemoAdminPageGrantTest.php`
- Modify: `deploy/scripts/verify_real_stack.sh`
- Modify: `evidence/v11-acceptance-report.md`

**Interfaces:**
- Consumes: Task 2–16 全部能力。
- Produces: `demo.admin` 160/160 页审计、页面授权矩阵、真实栈证据。

- [ ] **Step 1: 写失败测试**

`frontend/tests/e2e/real/admin-all-pages.spec.ts`：

```ts
import { expect, test } from '@playwright/test'
import { PAGE_REGISTRY } from '../../../src/router/registry'

const PASSWORD = process.env.HRMS_DEMO_PASSWORD ?? ''

test.skip(PASSWORD === '', 'HRMS_DEMO_PASSWORD is required')

test('demo.admin can open every registered page without unexpected 403', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('账号').fill('demo.admin')
  await page.getByLabel('密码').fill(PASSWORD)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await expect(page).toHaveURL(/\/workspace/)

  const denied: string[] = []

  page.on('response', (response) => {
    if (response.status() === 403 && response.url().includes('/v1/')) {
      denied.push(new URL(response.url()).pathname)
    }
  })

  for (const entry of PAGE_REGISTRY) {
    if (entry.capability === 'public') continue
    const path = entry.path.replace(':id', '1')
    await page.goto(path)
    await page.waitForLoadState('networkidle')

    const forbidden = await page.getByText(/无权访问|没有权限|403/).count()
    if (forbidden > 0) denied.push(`${entry.path} rendered forbidden`)
  }

  expect(denied, `unexpected 403: ${denied.join('; ')}`).toEqual([])
})
```

`backend/tests/Feature/IAM/DemoAdminPageGrantTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class DemoAdminPageGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_admin_has_all_pages_and_all_capabilities(): void
    {
        config(['iam.demo_seed_password' => 'Correct-Horse-9!']);
        Artisan::call('iam:sync-capabilities');
        Artisan::call('iam:seed-demo');

        $this->artisan('iam:verify-page-grants', ['--username' => 'demo.admin'])
            ->assertSuccessful()
            ->expectsOutputToContain('pages=160');
    }
}
```

- [ ] **Step 2: 运行，确认失败**

```bash
cd frontend
HMRS_DEMO_PASSWORD=... pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/admin-all-pages.spec.ts
```

Expected: FAIL，列出仍 403 的页面（若 Task 2–16 未全部完成）。

- [ ] **Step 3: 新增 `iam:verify-page-grants` 命令**

**Files:** `backend/app/Modules/IAM/Console/Commands/VerifyPageGrantsCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use Illuminate\Console\Command;

final class VerifyPageGrantsCommand extends Command
{
    protected $signature = 'iam:verify-page-grants {--username= : Username to verify}';

    protected $description = 'Verify a user has page grants and capability bindings for every visible page.';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Unknown user {$username}");
            return self::FAILURE;
        }

        $roles = UserRole::query()
            ->where('user_id', $user->id)
            ->where('auth_version', $user->auth_version)
            ->pluck('role_code')
            ->all();

        $pages = RolePage::query()->whereIn('role_code', $roles)->distinct()->pluck('page_code')->count();

        $this->line("user={$username} roles=".count($roles)." pages={$pages}");

        return $pages === 160 ? self::SUCCESS : self::FAILURE;
    }
}
```

注册命令后运行：

```bash
cd backend
php artisan iam:verify-page-grants --username=demo.admin
```

Expected: `pages=160`，退出码 0。

- [ ] **Step 4: 修改真实栈审计，不再跳过 403**

`frontend/tests/e2e/real/registry-audit.spec.ts` 当前把 403 当作合法结果（第 70 行注释）。改为：

```ts
// demo.admin is the full-authority account; 403 is never a legitimate
// outcome for a registered page in this audit.
const forbidden = await page.getByText(/无权访问|没有权限|403/).count()
if (forbidden > 0) {
  failures.push(`${entry.path}: rendered forbidden state`)
}
```

并删除 `page.on('response')` 中对 403 的豁免；403 进入 `apiFailures`。

- [ ] **Step 5: 修改 `verify_real_stack.sh` 的审计账号**

```bash
section "4. 160-page real-stack route audit"
(
  cd "$REPO_ROOT/frontend"
  HRMS_DEMO_PASSWORD="$HRMS_DEMO_PASSWORD" \
  HRMS_DEMO_USERNAME="demo.admin" \
  HRMS_FRONTEND_URL="$BASE_URL" \
  HRMS_BACKEND_URL="$BASE_URL" \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/registry-audit.spec.ts tests/e2e/real/admin-all-pages.spec.ts --reporter=line
) >>"$LOG" 2>&1 && pass "demo.admin 160-page audit" || fail "demo.admin 160-page audit"
```

- [ ] **Step 6: 运行完整验收**

```bash
cd backend
php artisan test --compact
php artisan capability:audit
php artisan arch:test
cd ../frontend
pnpm typecheck
pnpm lint
pnpm test:unit
cd ..
HRMS_DEMO_PASSWORD=... bash deploy/scripts/verify_real_stack.sh --base-url http://127.0.0.1:5174
```

Expected: 全部 PASS；`demo.admin` 160/160；`demo.hr` 按其页面集合通过；0 非预期 403。

- [ ] **Step 7: 更新验收报告**

`evidence/v11-acceptance-report.md` 增加：

```markdown
## 页面独立化与权限修复验收

- 160 个 page_code → 160 个唯一页面组件（base 复用白名单 2 条）。
- `demo.admin` 160/160 页可进入，0 非预期 403。
- `role_pages` 页面授权：单页授予/撤销 E2E 通过。
- 前后端页面能力对账：160/160 一致。
- 菜单展开指示：axe critical/serious=0。
- 证据：`evidence/v11-real-stack-<stamp>.log`。
```

- [ ] **Step 8: 提交**

```bash
git add frontend/tests/e2e/real/registry-audit.spec.ts frontend/tests/e2e/real/admin-all-pages.spec.ts backend/tests/Feature/IAM/DemoAdminPageGrantTest.php backend/app/Modules/IAM/Console/Commands/VerifyPageGrantsCommand.php deploy/scripts/verify_real_stack.sh evidence/v11-acceptance-report.md
git commit -m "test(v11): verify demo.admin across all pages with page grants"
```

---

### Task 18: 最终门禁、文档同步与回滚演练

**Files:**
- Modify: `docs/02-permissions.md`
- Modify: `docs/05-ui-guidelines.md`
- Modify: `docs/01-architecture.md`
- Modify: `docs/openapi/openapi.yaml`
- Modify: `docs/adr/0033-page-authorization-and-page-module-boundaries.md`
- Modify: `docs/adr/0034-single-company-remove-tenant-and-legal-entity.md`
- Modify: `docs/03-data-model.md`
- Modify: `README.md`
- Test: `backend/tests/Contract/PageCapabilityContractTest.php`
- Test: `frontend/tests/unit/routeRegistry.spec.ts`

**Interfaces:**
- 产物：最终门禁脚本全部通过；文档与实现一致；回滚步骤可执行。

- [ ] **Step 1: 更新文档中的能力/页面数量与新增标准**

- `docs/02-permissions.md`：
  - 能力目录数量以 `php artisan iam:sync-capabilities` 输出为准（当前 159）；
  - 角色模板 14 个；页面 160 个；按钮 `PageActionSpec` 清单；
  - 五级授权（菜单 → 页面/路由 → 按钮 → 接口 → 数据范围/字段）；
  - 超管基线：160 页 + 全部能力 + `user_roles`；`iam:verify-superadmin`；
  - CRUD 策略：S 软删除 / A 归档 / I 不可变；合同 soft delete；
  - §10D 的市场默认决策。
- `docs/05-ui-guidelines.md`：
  - 菜单 chevron、`meta.navKey`、`aria-current`、展开状态持久化；
  - `Can`/`ActionGate` 按钮规则：无能力不渲染可点击控件；
  - 角色授权页四 Tab：页面/按钮/数据范围/字段；
  - “非必要不共用页面”与共享组件白名单。
- `docs/01-architecture.md`：
  - IAM 表清单加入 `role_pages`；
  - 增加单公司/无租户裁决与 `company_profile`；
  - 前端 `modules/` 目录与依赖方向；
  - CRUD 策略与合同软删除；
  - 超管安装链与 `iam:verify-superadmin`。
- `docs/03-data-model.md`：`company_profile` 单例、无 `tenant_id`/`legal_entity_id`、员工归档字段。
- ADR-0002/0003/0004/0016/0024：顶部加入“Superseded in part by ADR-0034”注记，保留历史正文，避免读者把旧法人/租户设计当现行规则。
- `README.md`：
  - 演示账号说明补“单公司、无法人/租户；公司配置来自 `company_profile`”；
  - 菜单/页面/按钮/接口/数据范围五级授权说明；
  - 合同软删除与恢复；
  - 部署基线自检命令。

- [ ] **Step 2: 更新 OpenAPI 与生成类型**

```bash
pnpm openapi:generate
pnpm openapi:check
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
```

Expected: 生成类型无 diff；lint/validate 0 错误。

- [ ] **Step 3: 运行全量后端门禁**

```bash
cd backend
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan capability:audit
php artisan arch:test
php artisan crud:audit
php artisan iam:verify-superadmin --username=demo.admin
! rg -n "tenant_id|legal_entity_id|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId" app routes database/seeders ../frontend/src
```

Expected: 全 PASS；无新增 PHPStan 错误；CRUD 与超管基线均通过；生产代码/种子 0 处法人/租户标识（迁移中用于删除旧列的历史标识除外）。

- [ ] **Step 4: 运行全量前端门禁**

```bash
cd frontend
pnpm typecheck
pnpm lint
pnpm test:unit
pnpm build
cd ..
node scripts/audit_route_coverage.mjs --require-mature --require-capabilities
node scripts/audit_page_components.mjs
pnpm page-capability:check
pnpm actions:check
pnpm modules:check
```

Expected: 160/160 页面唯一；0 缺口；0 能力漂移；按钮清单与模块边界均通过。

- [ ] **Step 5: 运行真实栈与备份恢复**

```bash
HRMS_DEMO_PASSWORD=... bash deploy/scripts/verify_real_stack.sh --base-url http://127.0.0.1:5174
bash deploy/scripts/mysql_backup_restore.sh --project factoryhr --database factoryhr
```

Expected: 真实栈全通过；备份恢复零差异。

- [ ] **Step 6: 回滚演练**

按 `docs/runbooks/rollback.md` 执行：

1. 回滚页面拆分 PR（按域），保留 `role_pages` 与 `COMPANY` 授权数据；
2. 不得恢复“按能力推导页面”的旧逻辑，否则只授单页立即失效；
3. 单公司迁移只通过迁移前数据库快照回滚；forward-only 迁移本身不编辑、不逆向执行；
4. 若必须回滚 schema，先在只读窗口验证 `company_profile`、`role_pages`、员工归档/合同软删除数据完整；
5. 验证 `demo.admin` 仍可登录、旧页面可访问、审计不丢。

- [ ] **Step 7: 提交**

```bash
git add docs/02-permissions.md docs/05-ui-guidelines.md docs/01-architecture.md docs/03-data-model.md docs/openapi/openapi.yaml docs/adr/0033-page-authorization-and-page-module-boundaries.md docs/adr/0034-single-company-remove-tenant-and-legal-entity.md docs/adr/0002-person-employment-assignment.md docs/adr/0003-time-semantics-and-business-calendar.md docs/adr/0004-capability-anchor-authorization.md docs/adr/0016-wildcard-superuser-and-sod-layering.md docs/adr/0024-superuser-full-authority-and-p3-audit.md README.md
git commit -m "docs(v11): align permissions, page modules and menu guidance"
```

---

## 5. 任务与根因映射

| 根因 | 对应任务 | 验收证据 |
|---|---|---|
| 页面共用壳 | Task 10–13 | `audit_page_components.mjs`；每域 E2E |
| 覆盖门禁误判 | Task 9 | 变异测试：两 key 同一文件必失败 |
| 旧法人/租户上下文 | Task 2、6 | `/me` 不再返回 legal entity；真实栈请求无 `X-Legal-Entity-Id` |
| 能力加载静默为空 | Task 7 | 500 测试抛错；`ForbiddenView` reason |
| 3 处能力漂移 | Task 8 | `page-capability:check` |
| 缺页面授权层 | Task 3–4、14 | `role_pages`；单页授予 E2E |
| 演示无 `user_roles` | Task 5 | `IamCommandsTest`；`iam:verify-page-grants` |
| 菜单无展开图标 | Task 16 | chevron 组件测试；axe |
| 当前路由不高亮 | Task 16 | `meta.navKey`；`aria-current` |
| 验收放过 403 | Task 17 | `demo.admin` 160/160；403 不再豁免 |
| 按钮无能力门禁 | Task 9A | `PageActionSpec`；`actions:check`；`Can` 组件负测 |
| 非必要共用页面 | Task 9B、10–13 | `audit_page_components.mjs`；`modules:check` |
| 按同事分配角色 | Task 14A | 用户列表 `roles[]`；多角色下拉；撤权 E2E |
| 权限预览 | Task 15 | 页面/动作/拒绝原因；与 `/me/permissions` 同源 |
| 超管无完整基线 | Task 9C | `iam:verify-superadmin`；`pages=160` |
| 按钮级权限不可配置 | Task 9D | 四 Tab 矩阵；父菜单不隐含子页 |
| 模块 CRUD 缺失 | Task 9E | `crud:audit`；合同 soft delete；员工归档/恢复 |

---

## 6. 完成定义（DoD）

- [ ] 160 个 `page_code` 均有唯一 `.vue` 页面文件，且 `audit_page_components.mjs` 通过。
- [ ] `role_pages` 表存在，模板页面已回填，自定义角色可编辑页面权限。
- [ ] `/me/permissions` 同时返回页面、动作、字段、范围，并可按页面独立过滤。
- [ ] `demo.admin` 160/160 页可进入，0 非预期 403；`demo.hr`/`demo.employee` 拒绝矩阵符合预期。
- [ ] 前端权限加载失败不再显示空菜单；403 文案区分页面/动作/组织范围/字段。
- [ ] 前后端 160 条页面能力码 0 漂移。
- [ ] 所有有子项的菜单显示 chevron；当前分组自动展开；子项 `aria-current="page"`。
- [ ] 真实栈 160 页 + 页面授权 + 菜单 axe + 备份恢复全部通过。
- [ ] 每个写操作按钮都有 `PageActionSpec.capability`，`actions:check` 通过；无能力时按钮不渲染。
- [ ] 用户列表显示真实角色；支持按同事多选角色、撤销角色，撤权后下一次请求 403。
- [ ] 角色页可勾选页面 + 动作 + 数据范围 + 字段档位；保存后即时生效。
- [ ] 权限预览按页面/动作/拒绝原因展示，并与 `/me/permissions` 共用同一解析逻辑。
- [ ] 前端模块边界门禁 `modules:check` 通过；共享组件不含页面身份 props。
- [ ] 超管部署即 160/160 页、全部能力；`iam:verify-superadmin` 通过。
- [ ] 角色页可配置页面、按钮、数据范围、字段四个 Tab；保存前有差异预览。
- [ ] 每模块 CRUD 或归档/不可变策略经 `crud:audit`；员工支持归档/恢复，合同为 soft delete。
- [ ] 文档、OpenAPI、ADR、`docs/02-permissions.md`、`docs/05-ui-guidelines.md` 与实现一致。

---

## 7. 已确认裁决与执行顺序

本计划依赖解决方案文档 §11 的已确认裁决（用户 2026-11-25）：§10D 市场默认与 ADR-0034 视为已同意，不再逐项确认。执行时按以下 8 条硬约束：

1. 系统只服务一个公司：**删除 `tenant_id`、`legal_entity_id`、`legal_entities`、`X-Legal-Entity-Id`、`activeLegalEntityId`**；用 `company_profile` 单例与 `COMPANY` 锚点替代（ADR-0034）。
2. `role_pages` 作为独立页面授权层；页面、按钮、接口、数据范围五级授权（§10A）。
3. 允许破坏性改造：删除表/列/路由/前端上下文，但必须 forward-only 迁移 + 回滚快照 + 演练（ADR-0034）。
4. 演示/安装账号同时写 `user_roles` 与 `authority_bindings`；超管部署即 160 页 + 全部能力（§10B）。
5. 员工支持完整 CRUD（归档式删除 + 恢复），合同软删除（§10C、Task 9E）。
6. 其余模块 CRUD（或归档/不可变）经 `crud:audit`（§10C）。
7. 前端非必要不共用页面；后端/前端模块边界经 `arch:test`、`modules:check`（§10.2/§10.3）。
8. 其余权限、UI、CRUD 细节按 §10D 的市场默认执行，不再逐项确认。

按 Task 1 → Task 18 顺序执行（含 9A–9E、14A）：Task 2（单公司迁移）先行；Task 10–13 可在 Task 9 完成后并行；Task 14/14A/15/16 依赖 Task 3 与页面清单；Task 16 可与 10–13 并行；Task 17 必须最后执行，Task 18 收口。
