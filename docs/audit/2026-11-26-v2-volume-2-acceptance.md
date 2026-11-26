# 第 2 册验收记录（V2.1 Volume 2：权限内核、单公司与登录）

- 验收范围：Task 1–12（预检、schema cutover、运行时去 tenant/legal entity、权限工作台、动作清单、授权一致性、密码登录、五角色矩阵、迁移演练）。
- 结论：**YES — 第 2 册退出条件全部达成**，仅有 3 项已归档、归属第 3 册的产品缺陷（见 §6）。

## 1. 门禁结果（真实输出）

| 门禁 | 结果 | 证据 |
| --- | --- | --- |
| 后端全量 | **PASS** | `OK (1184 tests, 57606 assertions)` |
| 后端 Pint | **PASS** | `PASS 1179 files` |
| 后端 PHPStan | **PASS** | `[OK] No errors` |
| capability:audit | **PASS** | `Public routes (explicit allowlist): 6` / `Capability audit OK.` |
| arch:test | **PASS** | `Module boundaries OK.`（4 条既有非阻断警告） |
| 单公司严格扫描 | **PASS** | `NoTenantNoLegalEntityTest` `OK (4 tests, 6 assertions)`，在 worktree `backend/` 目录内执行；生产代码 0 命中（仅预检命令 2 行显式标记）。**注意**：若在该目录之外用相对路径调用 `vendor/bin/phpunit`，PHPUnit 会解析到主检出的配置并扫描另一份代码树，得到假红；复跑必须 `cd backend` 或显式 `--configuration backend/phpunit.xml`。 |
| 前端 typecheck | **PASS** | `vue-tsc -b` 无输出 |
| 前端 lint | **PASS** | `eslint . --max-warnings=0` |
| 前端单测 | **PASS** | `50 files / 348 tests` |
| OpenAPI lint | **PASS** | `252 paths, 324 operations, 3.1.0` |
| OpenAPI validate | **PASS** | `valid`，2 条既有健康探针 4xx 警告 |
| 动作清单 | **PASS** | `160 pages, 10 write pages, 9 pages with actions, 19 actions, 155 explicit write gaps, 14 owned capability gaps, 1 public exception` |
| 五角色矩阵 | **PASS** | `RoleMatrixTest` `OK (7 tests, 69 assertions)` |

## 2. 单公司迁移演练（MySQL 8.4.13，真实 Compose 服务）

演练在一个与演示库隔离的 `factoryhr_rehearsal` 库上执行；数据来源是把当时的真实库整库克隆（含 171 个旧列），因此演练覆盖的是一份真实的迁移前状态。

- 预检（迁移前）：`connection=mysql`，156 张表，171 个 `tenant_id`/`legal_entity_id` 列实例（与独立 SQL 计数一致），`company_profile=1`，`data_disposition=REBUILD`。
- `migrate:fresh --force`：**exit 0**，56 个迁移全部 DONE，含 `000300 migrate_legal_entity_bindings_to_company` 与 `000320 drop_tenant_and_legal_entity_columns`。
- 预检（迁移后）：`connection=mysql`，**155 张表，0 个旧列实例，`legal_entities` 已不存在，`company_profile=1`**。
- 迁移套件（MySQL 8.4 上直跑）：`OK (7 tests, 639 assertions)`；同一套件在 SQLite 内存库上是 `OK (7 tests, 327 assertions)`。断言数差异来自 MySQL 上额外的 schema/index 断言，两者都是通过。
- 恢复校验：把演练库 dump 后还原到独立库 `factoryhr_restore_check`，得到 **155 张表、0 个旧列**，说明备份→还原路径可用。

演练中发现并修复的真实缺陷：`company:preflight` 的 `Schema::getTables()` 在 MySQL 上未限定 schema，会把同实例内的其他数据库（例如恢复副本）当成自己的表来计数，导致删列后仍报 `legal_entities` 不存在。修复为按连接 schema 过滤（SQLite 走 `main` 分支）。

## 3. 复现命令

```bash
# 后端
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never
../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
../.tools/php/php.exe -d memory_limit=1G artisan capability:audit
../.tools/php/php.exe -d memory_limit=1G artisan arch:test

# 前端与契约
cd .. && pnpm openapi:validate && node deploy/scripts/openapi-lint.mjs && pnpm actions:check
cd frontend && pnpm typecheck && pnpm lint && pnpm test:unit

# 单公司演练（Compose MySQL）
COMPOSE_PROJECT_NAME=factoryhr \
MYSQL_DATABASE=factoryhr_rehearsal \
MYSQL_ROOT_PASSWORD='<测试库密码>' \
BACKUP_DIR=evidence/single-company \
bash deploy/scripts/single_company_rehearsal.sh
```

## 4. 冻结的跨册接口（第 3 册直接消费）

- `Principal` 无 `tenantId()`；`company_profile` 是单例配置而非隔离维度；COMPANY 锚点为 `anchor_id=null, anchor_key=0`。
- 授权入口：`Authorizer::allows/permits/constrain/fields`；绑定来源 `RoleTemplateCatalog` + `AuthorityBinding`。
- 权限工作台端点：`PUT /v1/roles/{id}/authorization`（原子保存页面+动作+范围+字段）、`POST/PATCH/DELETE /v1/system/menus*`、`PUT /v1/system/data-scopes/{id}`、`PUT /v1/system/field-policies/{field_key}`、`GET /v1/capabilities`（读能力为 `iam.permission.read`）。
- 动作真相源：`docs/generated/page-actions.json` + `frontend/src/generated/pageActions.ts`，经 `actionsForPage()` 读取，门禁 `actions:check` 含再生成一致性校验。
- 登录：仅账号+密码；MFA 默认关闭（`iam.mfa.enabled=false`），关闭时不短路能力校验。

## 5. 已知失败与归属

| 项 | 状态 | 说明 |
| --- | --- | --- |
| 真实栈 Playwright 矩阵 | **NOT RUN** | 本环境无法在浏览器内驱动完整演示登录流；规格已通过 `playwright test --config=playwright.real.config.ts --list`（6 个用例可收集） |
| 14 个页面能力缺口 | 已归档 | 每条都有 `Volume 3 Task N` 归属，无通配豁免 |
| 155 个未托管写操作 | 已归档 | 同上，属第 3 册各域任务 |

## 6. 发现的真实产品缺陷（归属第 3 册，不在本册修复）

| # | 严重度 | 现象 | 期望 | 实际 | 归属 |
| --- | --- | --- | --- | --- | --- |
| 1 | 高 | 工资专员无法列出工资批次 | `GET /v1/payroll/runs` 200 | 403：角色按 `PAYROLL_RUN` 授予 `payroll.run.read`，路由要求 `COMPANY` | Volume 3 Task 12 |
| 2 | 中 | 部门经理无法读员工目录 | ORG_UNIT 锚点可读本部门 | `LINE_MANAGER` 实为 MANAGED 锚点，目录 403 | Volume 3 Task 2/5 |
| 3 | 中 | 越权访问他人档案返回 403 而非 404 | 不泄露资源存在性 | 403 `CAPABILITY_DENIED` | Volume 3 Task 3 |

三条均在 `RoleMatrixTest` 中以注释标注并断言当前真实行为，未通过放宽断言掩盖；明细见 `docs/audit/2026-11-26-role-permission-matrix.md`。

## 7. 第 2 册退出判定

- 单公司 schema 与运行时：**达成**（MySQL 8.4 演练 + 严格扫描 + 迁移套件）。
- 权限模型（页面/动作分离、锚点、字段档位）：**达成**（动作清单 + 工作台 + 一致性测试）。
- 登录姿态：**达成**（密码唯一必需路径，MFA 可选且默认关闭，关闭时不短路鉴权）。
- 五角色矩阵：**达成**（正负用例 + 3 项缺陷已归属）。
- 剩余红线：无。第 2 册可关闭，进入第 3 册。
