# Volume 3 Task 12 — 薪资管理（payroll workspace）报告

日期：2026-11-27（Asia/Singapore）  
分支：`feat/v2.1-remediation`  
提交：见本文件生成后的提交记录（`feat(payroll): build the payroll workspace and fix the specialist run scope`）

## 1. 交付范围

| 类型 | 文件 |
|---|---|
| 前端页面 | `frontend/src/modules/payroll/pages/PayrollWorkspacePage.vue` |
| 前端页面 | `frontend/src/modules/payroll/pages/PayrollRunDetailPage.vue` |
| 前端组件 | `frontend/src/modules/payroll/components/PayrollRunTable.vue` |
| 前端组件 | `frontend/src/modules/payroll/components/PayrollActionBar.vue` |
| 前端组件 | `frontend/src/modules/payroll/components/PayslipDrawer.vue` |
| 前端 API | `frontend/src/modules/payroll/api.ts` |
| 前端页表 | `frontend/src/modules/payroll/pages/index.ts` |
| 后端控制器 | `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php` |
| 后端服务（缺陷修复） | `backend/app/Modules/Payroll/Services/PayrollRunService.php` |
| 后端路由（缺陷修复） | `backend/routes/api.php` |
| 后端测试 | `backend/tests/Feature/Payroll/PayrollWorkspaceTest.php` |
| 后端回归 | `backend/tests/Feature/Authorization/RoleMatrixTest.php` |
| 前端单测 | `frontend/tests/unit/PayrollWorkspace.spec.ts` |
| 前端 E2E | `frontend/tests/e2e/real/payroll-workspace.spec.ts` |
| 契约 | `docs/openapi/openapi.yaml`、`frontend/src/generated/api.ts` |
| 矩阵/台账 | `backend/tests/Authorization/endpoint_matrix.php`、`docs/audit/2026-11-25-v11-legacy-routes.md` |
| 路由接线（lead 明确授权） | `frontend/src/router/registry.ts`、`frontend/src/router/pageComponents.ts`、`frontend/src/modules/timepay/pages/index.ts` |
| 门禁计数同步 | `scripts/audit_page_components_lib.mjs`、`scripts/audit_route_coverage.mjs`、`scripts/tests/audit_page_components.test.mjs`、`frontend/tests/unit/routeRegistry.spec.ts`、`frontend/tests/unit/pageModel.spec.ts`、`frontend/tests/unit/navigation.spec.ts`、`frontend/tests/unit/pageComponentUniqueness.spec.ts`、`frontend/tests/unit/shellMenu.spec.ts`、`frontend/tests/unit/timePayModulePages.spec.ts`、`frontend/tests/unit/timePayrollPages.spec.ts` |

## 2. 强制缺陷修复（PAYROLL_SPECIALIST）

原缺陷：`GET /v1/payroll/runs` 使用 `capability:payroll.run.read,COMPANY`，而 `PAYROLL_SPECIALIST` 只持有 `PAYROLL_RUN` 锚点能力：
- 列表被整体拒绝（无法列出自己准备的批次）；
- 若简单去掉锚点，又会把全部批次暴露给任何带有 `payroll.run.read` 的用户。

修复：
1. 路由守卫改为 feature 级 `capability:payroll.run.read`，回答“是否具备薪资批次功能权限”。
2. 控制器把 `userId` 与总部业务日期传给 `PayrollRunService::listRuns()`。
3. `listRuns()` 通过 `PayrollRunMembershipDirectory::runIdsForUser()` 取得成员批次，并 `whereIn('id', $memberRunIds)` 限制每一行；默认拒绝，无成员则空列表。
4. `GET /runs/{id}` 仍为 `PAYROLL_RUN` 锚点守卫，非成员访问另一个专员的批次返回 `403 CAPABILITY_DENIED`（回归测试覆盖双向）。

安全性质：不扩大读取范围；列表与详情都按“当前用户是准备人/复核人/批准人或发布人”的真实成员关系裁剪。测试同时使用 `FakePayrollRunMembershipDirectory`（双专员隔离）和真实 `EloquentPayrollRunMembershipDirectory`（完整生命周期后列表可见）证明。

## 3. 功能实现

### 列表 `/payroll`
- 真实 `GET /v1/payroll/runs`，服务端分页/筛选（`period`、`status`、`page`、`page_size`）。
- 列：期间、批次类型、阶段、明细状态、人数、应发/扣款/实发合计、发放日。
- 阶段中文标签：`草稿 / 已提交 / 已批准 / 已发布`；底层状态同时显示（`DRAFT`、`INPUT_LOCKED`、`CALCULATED`、`REVIEWED`、`APPROVED`、`PUBLISHED`、`PAID`、`CLOSED`、`CANCELLED`），不把真实状态伪装成四态。
- 金额以整数最小单位（分）从 API 读取，渲染时格式化为人民币；缺失键显示“已按字段权限隐藏”，绝不补 0 或伪造行。

### 详情 `/payroll/runs/:id`
- 批次信息、状态、人数、金额合计、快照版本、输入锁定时间。
- `GET /employees`（工资条来源，逐员工金额/失败原因）、`GET /inputs`（不可变输入证据）、`GET /reconciliation`（对账与差异）；三个子读取独立失败时进入“部分数据”状态，而非整页空白。
- 员工行可打开工资条抽屉；按服务端实际返回的应发、个人社保、个人公积金、个税、扣款、实发、单位成本渲染，不使用虚构行项目。
- 已发布批次只读：准备/试算/复核/批准/发布全部禁用并显示中文原因；银行/税务导出仍可用。

### 动作栏
- 真实动作：`lock-inputs`、`calculate`、`review`、`approve`、`publish`、`mark-paid`、`close`。
- 每个动作独立能力：`payroll.run.prepare` / `payroll.run.read` / `payroll.run.review` / `payroll.run.approve` / `payroll.run.publish` / `payroll.run.close`；`payroll.export` 独立控制导出。
- 二次确认对话框明确显示批次期间（如 `2026-11`）与后果。
- 非法迁移与能力缺失均禁用按钮并给出中文原因；409 稳定 reason 映射为中文，不展示原始英文。
- 所有写请求携带 `Idempotency-Key`。

### 导出
- 只调用真实 `GET /v1/payroll/runs/{id}/exports/bank|tax`；使用服务端返回的 `{filename, content, sha256}` 通过本地 Blob 触发下载。
- 页面不拼接、不猜测任何导出 URL；测试断言请求路径和返回值摘要。

## 4. 五态与安全默认

- 五态：`loading`、`empty`、`error`、`forbidden`、`partial`。
- 无 `payroll.run.read` 时不发起请求，显示 forbidden 说明。
- 缺失金额键进入 partial 提示；不把“无权限”显示成 0。
- 所有路由能力由服务端再次校验，前端禁用只是体验层。

## 5. 路由接线（lead 授权后的范围扩张）

- `/payroll` 的 `componentKey` 从 `payroll/index` 改为 `payroll/workspace`，指向新工作台。
- 新增一条 registry 详情路由：`/payroll/runs/:id`，`kind: 'detail'`、`menu: null`、`detailOf: 'payroll'`，组件 `payroll/runs/by-id`。
- `frontend/src/modules/payroll/pages/index.ts` 成为唯一 loader；`timepay/pages/index.ts` 删除 `payroll/index`，保留其余 42 个 Wave 3 键。
- registry 由 160 条变为 161 条（业务 141 / 平台 14 / 基础 6；detail 11 → 12）；同步了单元测试和脚本里的冻结计数。
- `scripts/audit_route_coverage.mjs` 以显式 `DELIBERATE_DETAIL_ROUTES = {'/payroll/runs/:id'}` 允许这条不在冻结 03A 清单中的深链，同时保持 140 条清单不变。

## 6. 契约

- `GET /v1/payroll/runs` 的 OpenAPI 描述补充：feature 级守卫 + 按成员关系行级裁剪。
- `PayrollRunSummary` 增加 `employee_count`（integer，minimum 0）为必填字段，与真实响应一致。
- `frontend/src/generated/api.ts` 重新生成。
- `endpoint_matrix.php` 中该行 anchor 改为 `PAYROLL_RUN` 并注明行级裁剪。
- `docs/audit/2026-11-25-v11-legacy-routes.md` 第 142 行 anchor 更新为 `PAYROLL_RUN`。

## 7. 验证证据（本会话内实际执行）

| 命令 | 结果 |
|---|---|
| `phpunit --filter='PayrollWorkspaceTest|PayrollRunTest|Payslip'` | `OK (16 tests, 145 assertions)` |
| `phpunit` 全量（`-d memory_limit=512M`） | `OK (1222 tests, 58046 assertions)` |
| `vendor/bin/pint --test` | PASS (1187 files) |
| `vendor/bin/phpstan analyse` | `[OK] No errors` |
| `php artisan capability:audit` | `Capability audit OK` |
| `php artisan arch:test` | `Module boundaries OK`（4 条既有非阻断 WARN） |
| `node deploy/scripts/openapi-lint.mjs` | `OK (256 paths, 330 operations, 3.1.0)` |
| `pnpm openapi:validate` | valid（2 条既有 warning） |
| `pnpm typecheck` | PASS |
| `pnpm lint` | PASS (`--max-warnings=0`) |
| `pnpm test:unit` | `62 files, 484 tests passed` |
| `pnpm page-components:check` | `ok: true, total 161, mapped 155 + 6 base` |
| `pnpm modules:check` | `ok: true` |
| `node scripts/audit_route_coverage.mjs` | `ok: true, entries 161` |
| Playwright `--list payroll-workspace.spec.ts` | `3 tests in 1 file` |
| Playwright `--list`（全 real 套件） | `35 tests in 11 files` |

已存在、与本任务无关的非绿门禁（基线即失败，未修改其断言）：
- `pnpm page-model:check`：既有 `action-like path` 与 `PeopleWorkspace.vue` 跨模块 tab 失败。
- `audit_action_manifest.mjs` / `audit_user_copy.mjs`：既有失败，本任务未触碰相关清单。

## 8. 未提交 / 并发工作隔离

未暂存任何并发 People / Task 4 / Task 8 文件：
`EmployeeProfileController.php`、`EmploymentContractController.php`、`EmployeeProfileApiTest.php`、`EmployeeDetailPage.vue`、`employee-profile.spec.ts`、`ContractWorkspace.spec.ts`、`EmployeeProfileSection.spec.ts`、`MovementWorkspace.spec.ts` 等。

本次提交只包含本报告列出的 payroll 范围、授权接线文件与为保持门禁一致的计数/契约文件。

## 9. 已知限制

- 真实 E2E 套件需要 `HRMS_DEMO_PASSWORD` 与已 seed 的 demo 租户；本次按任务要求执行了 Playwright `--list` 3 条，未在无 demo 凭据的环境伪造执行结果。
- 工资条抽屉只展示 API 返回的聚合金额；后端当前没有 run 级 payslip-lines 端点，因此不展示虚构的收入/扣款行项目。
- 导出为后端 CSV adapter，页面如实提示不是银行清算文件或税务申报文件。
