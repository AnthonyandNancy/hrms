# 页面/模块授权整改：实现与验收审计（2026-11-26）

**分支：** `feat/page-auth-remediation`
**Worktree：** `D:\code\ai\factoryHR\.worktrees\page-auth-remediation`
**基线：** `2c1a1cc` → 本报告时 `e313263`（16 个提交）
**依据文档：**
- `docs/superpowers/specs/2026-11-25-page-module-authorization-remediation-design.md`
- `docs/superpowers/plans/2026-11-25-page-module-authorization-remediation-plan.md`
- `docs/adr/0033-page-authorization-and-page-module-boundaries.md`
- `docs/adr/0034-single-company-remove-tenant-and-legal-entity.md`

> 本报告只记录实际执行过的命令与输出。未执行、未通过或仍失败的项目均单列，不以“部分通过”表述为通过。

---

## 1. 结论摘要

| 类别 | 状态 |
|---|---|
| 页面唯一性与模块边界 | **已通过**：160/160 页映射，`page-components:check` / `modules:check` 绿 |
| 成熟业务视图覆盖 | **已通过**：`matureViews=160, fallbackViews=0` |
| 角色页面授权 + 按钮/范围/字段四 Tab | **已实现并通过单测**（含保存前 diff 与高风险原因） |
| 权限预览 | **已实现并通过后端 API 测试**，与 `/me/permissions` 共用 `PermissionProjection` |
| 按同事分配/撤销角色 | **已实现并通过 API 测试**（撤权重放快照 + 会话撤销） |
| 超管 160/160 基线 | **已通过**（installer 输出 `pages=160/160 capabilities=163/163 wildcard=yes`） |
| 前端全量单测 / lint / typecheck / build | **通过**：44 文件 / 302 测试，ESLint 0 warning，生产构建成功 |
| 后端全量测试 | **未全绿**：1121 测试中仅 `NoTenantNoLegalEntityTest` 失败 |
| 单公司破坏性 cutover（Task 2） | **未完成**：`tenant_id` / `legal_entity_id` 仍广泛存在 |
| 真实栈 160 页验收 | **未取得有效通过证据**（详见 §7） |

**因此本次交付不能宣称 “Task 1–18 全部完成” 或 “生产就绪”。** 已完成的核心授权整改可独立验收；单公司列清除与真实栈验收仍属未完成项。

---

## 2. 已实现的代码变更

### 2.1 页面授权内核
- `role_pages` 表（迁移 `2026_11_26_000100_create_role_pages_table.php`），唯一页面可见性来源。
- `RolePageService` / `RolePageController`：`GET|PUT /v1/roles/{id}/pages`；模板角色 409 `TEMPLATE_ROLE_IMMUTABLE`；未知页面 422 `UNKNOWN_PAGE_CODE`；修改后重放受影响用户快照。
- `GET /v1/pages/catalog`：完整 160 页目录（`iam.role.manage` 守卫），供角色编辑矩阵使用，不按调用者授权过滤。
- `MeController::permissions()` 改为消费 `PermissionProjection`；页面可见 = 角色页面授权 **且** 所需能力具备；父菜单不隐含子页。
- `PermissionProjection` 同时驱动 `/me/permissions` 与 `GET /v1/users/{id}/permissions-preview`，拒绝原因稳定为 `PAGE_NOT_GRANTED` / `CAPABILITY_NOT_GRANTED` / `MENU_HIDDEN`。

### 2.2 用户与角色管理
- `GET /v1/users` 每项返回真实 `roles[]`（来自 `user_roles`，按 `auth_version` 与有效期过滤）。
- `DELETE /v1/users/{id}/roles/{roleCode}`：删除角色行与对应能力包、重放快照、撤销该用户全部会话。
- `SystemUsersPage.vue` 独立模块页面：角色下拉、原因、有效期、撤销、权限预览面板（页面/动作/拒绝原因）。
- `SystemRolesPage.vue` 独立模块页面：页面/按钮/数据范围/字段四 Tab；保存前差异预览；高风险能力（`*`、`iam.binding.manage`、`P3_RESTRICTED`）必须填写至少 8 字符原因。

### 2.3 页面与模块拆分（并行域）
| 域 | 页面数 | 模块清单 |
|---|---|---|
| People | 39 | `frontend/src/modules/people/pages` |
| TimePay | 43 | `frontend/src/modules/timepay/pages` |
| Talent | 36 | `frontend/src/modules/talent/pages` |
| Platform | 36 | `frontend/src/modules/platform/pages` |
| Base | 6 | `frontend/src/views`（登录/工作台/403/404/账户安全 ×2，已记录唯一共享例外） |

### 2.4 前端授权加载与路由守卫
- `AuthApi.permissions()` + HTTP/mock 实现；`SessionStore` 的 `pages`、`permissionsError`、`canPage()`。
- `/me/permissions` 加载失败**不降级为空菜单**：保留已认证主体并暴露 `permissionsError`，同时抛出错误。
- `pageAccessGuard()` 抽出为可测试纯函数：先判页面授权（`PAGE_NOT_GRANTED`），再判能力（`ACTION_NOT_GRANTED`）。

### 2.5 安装与部署修复
- `InstallCommand`：位置参数 `flag/state` 修正；`--enable=payroll,time,self_service` 映射到 `payroll_enabled` 等真实 flag，未知值显式失败。
- `deploy/scripts/docker_up.sh`：改用 `--company-code/--company-name`；补跑 `iam:seed-demo`。

---

## 3. 已通过的门禁与证据

### 3.1 页面与模块
```bash
node scripts/audit_page_components.mjs
# ok=true total=160 moduleMapped=154 baseMapped=6 mappedIncludingBase=160
# missingMappings=[] missingFiles=[] staleManifestKeys=[] failures=[]

node scripts/audit_frontend_modules.mjs
# ok=true scanned=186 pagesScanned=158 moduleComponentsScanned=12
# sharedComponentsScanned=19 allowlistEntries=1 failures=[]

node scripts/audit_action_manifest.mjs
# actions:check OK (160 pages, 3 write pages, 2 pages with actions, 3 actions, 1 public write exceptions)

node scripts/audit_route_coverage.mjs --require-mature
# matureViews=160 fallbackViews=0 failures=[]
```

### 3.2 后端质量门禁
```bash
cd backend
php vendor/bin/pint --test
# PASS 1168 files

php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
# [OK] No errors

php artisan capability:audit
# Public routes (explicit allowlist): 6
# Capability audit OK.
# Warnings (non-blocking): 4

php artisan arch:test
# Module boundaries OK.

php artisan crud:audit
# CRUD audit OK: 323 guarded business routes, employees archive/restore,
# contracts soft-delete/restore.
```

### 3.3 前端
```bash
cd frontend
pnpm typecheck
# exit 0

pnpm lint
# exit 0（--max-warnings=0）

VITE_API_MODE=http pnpm build
# ✓ built in 14.73s（仅既有 chunk >1200 kB 提示）

pnpm exec vitest run --maxWorkers=4
# Test Files 44 passed (44)
# Tests      302 passed (302)
```

### 3.4 后端定向验收
```bash
cd backend
php -d memory_limit=1G artisan test --compact --filter='PermissionPreviewApiTest|MePermissionsTest|MePermissionsPageGrantTest'
# 10 passed (69 assertions)

php -d memory_limit=1G artisan test --compact --filter='PageCatalogApiTest'
# 2 passed (10 assertions)

php -d memory_limit=1G artisan test --compact --filter='PageCatalogApiTest|LegacyRouteInventoryTest|GapCompletionCapabilityMatrixTest|IamRouteMatrixTest|OpenApi'
# 33 passed (6566 assertions)
# OpenApiSchemaContractTest: 0 violations across 323 operations

php -d memory_limit=1G artisan test --compact --filter='UserRoleAssignmentApiTest'
# 3 passed (19 assertions)
```

### 3.5 OpenAPI / 路由清单
```bash
pnpm openapi:validate
# valid；2 个既有 health 警告
pnpm openapi:generate
# frontend/src/generated/api.ts 已更新
node deploy/scripts/openapi-lint.mjs
# openapi-lint: OK (250 paths, 322 operations, 3.1.0)
```
路由清单 `docs/audit/2026-11-25-v11-legacy-routes.md` 已同步到 323 条，`LegacyRouteInventoryTest` 断言一致。

### 3.6 安装器 / 超管基线
```
administrator_role   CREATED  Bound SYSTEM_ADMIN to user 1 for 366 day(s).
superadmin_baseline  CREATED  user=demo.admin role=SYSTEM_ADMIN
                              pages=160/160 capabilities=163/163 wildcard=yes roles=1 bindings_company=yes
feature:payroll      CREATED  payroll_enabled=on tenant=1 audited=yes
feature:time         CREATED  time_enabled=on tenant=1 audited=yes
feature:self_service CREATED  self_service_enabled=on tenant=1 audited=yes
Install complete.
```

---

## 4. 未通过项

### 4.1 后端全量测试（唯一失败）
```bash
cd backend
php -d memory_limit=2G vendor/bin/phpunit --colors=never
# Tests: 1121, Assertions: 51163, Failures: 1
# 1) NoTenantNoLegalEntityTest::test_target_code_has_no_tenant_or_legal_entity_identifiers
```
扫描仍发现约 250 个生产文件包含 `tenant_id` / `legal_entity_id` / `LEGAL_ENTITY`。
当前生产代码中仍有约 1340 处 `tenant_id`、603 处 `legal_entity_id`。这不是测试过时，而是 ADR-0034 的破坏性 cutover 未完成。

**影响：** 因此不能声称“单公司改造完成”；`legal_entities` 表、旧列、旧锚点语义仍在运行路径中。

### 4.2 单公司 cutover 尝试（未合入）
已完成并验证、但**未合入主干**的探查性改动（存于 `git stash`：`wip-single-company-cutover-v2`）：
- `AuthenticatedPrincipal` 去掉持久化 `tenantId`，访问器固定返回 1；
- `RequestContext::setActor` 去掉 tenant 形参；
- 两个 legal-entity 上下文中间件降为 no-op 并重命名为 Company 语境；
- 约 20 个“跨租户隔离”测试改写为“未知资源 404 / 单实例唯一性”；
- 该中间状态测试数达 1041 通过，但仍有 16 个 Reporting 测试与 1 个扫描门禁未清。

**未合入原因：** 迁移删除列后，组织/薪资/考勤/人才等模块仍有大量 `legal_entity_id` 读写与契约签名（约 600 处），需跨模块重构并回归，不能在本次剩余预算内安全完成。合入半成品会破坏 `arch:test`/运行路径。已明确记录为未完成项，未以放宽扫描代替。

### 4.3 四 Tab / 页面拆分的实现深度
- 页面唯一性通过“一页一 `.vue`”达成，但 People/Talent/Platform 的页面多为**配置驱动薄包装**，业务分支仍在模块共享壳（`PeopleWorkspace`、`TalentPageShell`、`PlatformWorkspace`）内部按 `config` 分支。
- 因此“代码物理拆分”通过，但“每页独立业务实现”未完全达成；`PlatformWorkspace.vue` 仍约 4,700 行。
- 已在 gate 中保证页面文件唯一、无 `componentKey`/路由身份 prop，但没有宣称业务逻辑已彻底分页。

### 4.4 真实栈 160 页验收（未取得有效通过证据；后续已缩小到单一失败点）
执行过的命令（两次，均失败）：
```bash
HRMS_DEMO_PASSWORD='Demo!2026Synthetic' \
HRMS_FRONTEND_URL='http://127.0.0.1:5174' \
bash deploy/scripts/verify_real_stack.sh --base-url http://127.0.0.1:5174
```
证据文件：
- `evidence/v11-real-stack-20261126T031558Z.log`
- `evidence/v11-real-stack-20261126T034045Z.log`

结果：
- PASS：就绪检查、release traceability、post-deploy smoke、MySQL 备份恢复；
- FAIL：`demo login`（401）、`/v1/me` 401、160 页 registry audit。

**根因与修复进展：**
1. 首次失败：运行镜像未执行 `iam:seed-demo`，库内只有 `demo.admin`，而验收脚本默认登录 `demo.hr`。
2. 已修复：`docker_up.sh` 补 `iam:seed-demo/people/talent`，compose 注入 `DEMO_SEED_PASSWORD`，并改为失败即停。
3. 重跑结果（`evidence/v11-real-stack-20261126T043259Z.log`）：
   - PASS：readiness、release traceability、post-deploy smoke、demo login、`/v1/me`、未认证 401、MySQL 备份恢复；
   - FAIL：仅 160 页 registry audit。

**当前唯一失败点：** 浏览器加载 `/login` 后 `#app` 始终为空，无 pageerror，只有一次预期的 `/v1/me` 401；Playwright 在等待 `#login-username` 时 15 分钟超时。已核实：
   - 主包与 179 个引用 chunk 全部 HTTP 200；
   - 镜像内确实包含本轮构建（`bootstrap.failed` 文案存在）；
   - 容器内存充足（13.5 MiB / 15.5 GiB）；
   - `main.ts` 已加入 bootstrap 失败兜底文案（提交 `7ad52ed`），但没有出现该文案，说明并非捕获到的异常，而更可能是浏览器渲染进程被关闭/主线程阻塞。

**结论：** 真实栈验收仍未通过，本报告不将其计为通过。需在有图形/更长时限的稳定浏览器环境中复现并定位（下一步：抓取 `trace.zip` 或用 `--headed` 运行单页）。

---

## 5. V1.1 production gate 阶段结果

```bash
PATH="/d/code/ai/factoryHR/.tools/php:$PATH" \
V11_PHP=/d/code/ai/factoryHR/.tools/php/php.exe \
node scripts/v11_production_gate.mjs
```
证据：`evidence/v11-production-gate-2026-11-26T03-14-58-947Z.log`

| 阶段 | 结果 |
|---|---|
| gap-zero | PASS（63 backend gaps closed） |
| openapi | PASS |
| capability | PASS |
| negative-tests | PASS |
| migration | PASS（forward-only rehearsal on empty DB） |
| real-stack | FAIL（缺 `HRMS_DEMO_PASSWORD` / 未部署本分支） |
| release-trace | SKIP |
| coverage | SKIP |

**结论：** gate 整体失败，失败点在真实栈；不能表述为“production gate passed”。

---

## 6. DoD 逐项对照

| # | 完成定义 | 状态 | 证据 / 说明 |
|---|---|---|---|
| 1 | 160 页均唯一 `.vue`，page gate 通过 | ✅ | `mappedIncludingBase=160`，failures=[] |
| 2 | `role_pages` 存在、模板回填、自定义角色可编辑 | ✅ | `RolePagesTest`、`RolePagesApiTest`、`RolePageService` |
| 3 | `/me/permissions` 返回页面/动作/字段/范围并可按页过滤 | ✅ | `MePermissionsTest`、`MePermissionsPageGrantTest` |
| 4 | `demo.admin` 160/160，0 非预期 403；hr/employee 拒绝矩阵 | ⚠️ | 基线 160/160 已由 installer 证明；真实栈浏览器验收未通过 |
| 5 | 权限加载失败不显示空菜单；403 区分页面/动作 | ✅ | `sessionPageGuard.spec.ts`、`routerPageGrant.spec.ts` |
| 6 | 前后端 160 能力码 0 漂移 | ✅ | `audit_action_manifest.mjs`（160 页）、能力审计 |
| 7 | 菜单 chevron、自动展开、`aria-current` | ✅ | `shellMenu.spec.ts` 7/7 |
| 8 | 真实栈 160 页 + 页面授权 + axe + 备份恢复 | ❌ | 仅备份恢复通过；160 页与登录失败 |
| 9 | 每个写操作有 `PageActionSpec.capability` | ✅ | `actions:check`（160 页 / 3 写页 / 1 公共例外） |
| 10 | 用户列表真实角色、多选角色、撤销即时 403 | ✅ | `UserRoleAssignmentApiTest` 3/3；`systemUsersPage.spec.ts` 3/3 |
| 11 | 角色页四 Tab + 保存后即时生效 | ✅ | `rolePermissionMatrix.spec.ts` 2/2；`RolePagesApiTest` 重放快照 |
| 12 | 权限预览与 `/me/permissions` 同源 | ✅ | `PermissionProjection` 单一实现；`PermissionPreviewApiTest` |
| 13 | 模块边界门禁、共享组件无页面身份 props | ✅ | `modules:check` scanned=186 failures=[] |
| 14 | 超管部署即 160/160 + 全部能力 | ✅ | installer `superadmin_baseline` |
| 15 | 四 Tab 保存前差异预览 | ✅ | `role-diff` + 高风险原因测试 |
| 16 | CRUD/归档策略经 `crud:audit` | ✅ | 323 guarded routes，员工归档/合同软删除 |
| 17 | 文档/OpenAPI/ADR/权限/UI 文档一致 | ⚠️ | OpenAPI 323 操作通过；`docs/02-permissions.md`、`docs/05-ui-guidelines.md` 未逐条同步 |
| 18 | 单公司删除 tenant/legal entity | ❌ | 约 250 文件仍含旧标识；后端全量 1 红 |

图例：✅ 通过 / ⚠️ 部分通过或未完整验证 / ❌ 未通过。

---

## 7. 变更清单（关键提交）

```
e313263 fix(audit): resolve mature views from module page manifests
40da222 chore(deploy): use company install options
40ea61b fix(install): map feature flags and update deploy arguments
2fe8104 chore: pass pint, phpstan and frontend lint/build gates
5e4aff7 feat(iam): add page catalog and four-tab role authorization matrix
8049b2f fix(ui): make page guard testable and grant pages in shell fixture
7e9e8be feat(iam): permission preview, user role revocation and page-aware routing
788e22e refactor(talent): split workspace into one page component per page_code
9d43b1d refactor(people): split workspace into one page component per page_code
60ca817 refactor(platform): split workspace into one page component per page_code
ab6ecf1 refactor(timepay): split workspace into one page component per page_code
b3062f0 test(frontend): enforce module boundaries and shared component rules
9eaba55 test(v11): add page component uniqueness gate
ec8305e test(ui): add page uniqueness, action manifest and module boundary gates
b02a671 chore(tooling): add frontend gate support and test declarations
7b14c3b feat(iam,people): role-page API, superadmin baseline and crud audit
2c1a1cc feat(iam): add single-company profile, role pages and page authorization
```

**提交粒度说明：** `ec8305e` 合并了 Task 9/9A/9B 的门禁与工具改动，偏离“一任务一提交”的约定。本报告如实披露，不做 HEAD 重写。

---

## 8. 后续必须先做的事（按优先级）

1. **完成 Task 2 单公司 cutover**
   - 启用迁移 `2026_11_26_000060_drop_tenant_and_legal_entity_columns.php` 与 `SingleCompanyMigrationTest`；
   - 逐模块替换约 600 处 `legal_entity_id` 读写（Organization → Payroll → Time → People → Lifecycle/Talent）；
   - 删除 `LegalEntity` 模型与 `legal_entities` 表引用；
   - 跑全量后端（目标 0 failures）与 `migration_rehearsal.sh`。
2. **重新执行真实栈验收**
   - 用修复后的 `docker_up.sh --reset --demo`（已含 `iam:seed-demo`）启动；
   - 再跑 `verify_real_stack.sh`，要求 160 页 0 非预期 403；
   - 保存新的 `evidence/v11-real-stack-*.log`。
3. **同步文档**
   - `docs/02-permissions.md`、`docs/05-ui-guidelines.md` 增加四 Tab、页面授权与拒绝原因说明；
   - 更新 `docs/12-plan-completion-inventory.md` 的完成状态。
4. **记录并清理 cutover stash**
   - `git stash list` → `wip-single-company-cutover-v2` 是未完成探查，不应长期滞留；完成后删除或按任务拆分重做。
