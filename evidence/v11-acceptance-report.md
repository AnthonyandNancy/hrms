# V1.1 缺口补全 — 验收报告

> 本报告记录 **63 项“后端接口待补”归零** 这一轮交付的事实。
> 所有数字均可在本仓库用下列命令复现，未使用任何伪造数据、伪造接口或伪造 E2E。

- 分支：`feat/v11-gap-completion`
- 基线：`3f192d7`（文档确认）
- 交付 release：`409bff4`
- 计划：`docs/superpowers/plans/2026-11-25-v11-gap-completion-implementation-plan.md`（24 Task）
- 设计：`docs/superpowers/specs/2026-11-25-v11-gap-completion-design.md`（Accepted）

## 1. 功能完整：63 项缺口归零

| 域 | 起始缺口 | 交付后 |
|---|---:|---:|
| People / Organization | 7 | **0** |
| Time / Payroll | 13 | **0** |
| Talent | 23 | **0** |
| Platform | 20 | **0** |
| **合计** | **63** | **0** |

验证命令与结果：

```bash
node scripts/audit_route_coverage.mjs --require-mature --require-capabilities
# ok=true, mode=capability-complete,
# clusters.total=63, gapMarkers=0, membershipDrift=0, failures=[]
```

补充守卫：
- `scripts/lib/gapMarkers.mjs` **fail-closed**：解析到的标记数必须等于文件中的原始出现次数，
  否则报 `unparsed` 并失败（格式化改动无法把真实缺口变成绿色）。
- `scripts/lib/clusterMembership.mjs` 冻结了 63 条 `componentKey → 集群` 映射；
  等量互换 K1↔K4 会被 `membershipDrift=2` 拦住（已做变异验证）。
- Vue 模板中不得硬编码页码级缺口文案；`hardcoded=0`。

## 2. 契约完整

- OpenAPI 3.1：**236 paths / 314 operations**（起始 163 / 193）。
- `OpenApiContractTest` / `OpenApiSchemaContractTest`：
  - 成功响应 schema 缺失：**0**
  - 错误集缺失：**0**
  - `x-capability` 与路由中间件不一致：**0**
- `frontend/src/generated/api.ts` 由 OpenAPI 重新生成并通过 `openapi:check`。

## 3. 权限完整

- `CapabilityCatalog`：**156** 个能力码（112 基线 + 43 新 + 1 个补登记的历史遗漏 `time.attendance.reopen`）。
- `capability:audit`：**OK**，公共白名单保持 **6** 条。
- 修复了两个审计盲区：
  1. wildcard 曾把**未登记**的能力码也当作已授予 → 现在路由引用未知码直接失败；
  2. 中间件形如 `capability:code,ANCHOR,id`，锚点后缀曾被当作能力码一部分 → 已正确切分。
- `GapCompletionCapabilityMatrixTest`：**314** 个操作全部具备 ≥4 类拒绝用例；
  LEGAL_ENTITY 锚点具备跨法人拒绝，高风险具备 MFA/CSRF 拒绝，SELF 锚点不虚构跨法人拒绝。
- 新增结构性测试，并据此修正 **8 处**历史遗留的“授予锚点不在能力锚点集合内”（此前会被静默拒绝）。

## 4. 数据完整

- 前向迁移：新增迁移均带 `2026_11_25_1xxxxx_*` 前缀、匿名类，未修改任何已合并迁移。
- `deploy/scripts/migration_rehearsal.sh`：**PASS**（空库全量迁移演练）。
- 备份恢复零差异：`deploy/scripts/mysql_backup_restore.sh`（MySQL 8.4，见真实栈日志）。
- 金额：新增薪酬/福利/差旅/报表金额路径全部使用**整数最小单位**；
  `git diff` 浮点扫描无 `floatval` / `(float)` / `/100`。

## 5. 真实栈完整

- 160 页审计 + 12 集群工作流审计（`frontend/tests/e2e/real/`）在真实 Compose 栈执行。
- 集群 E2E 断言：每个集群页面必须调用其真实端点、不得渲染缺口面板、不得落入错误边界；5xx 除已声明的语义 503 外一律失败。
- 证据写入 `evidence/v11-real-stack-<stamp>.log`。

## 6. 可运维

- `/v1/meta` 返回部署 commit；`scripts/check_release_traceability.mjs` 校验镜像 SHA 与仓库 HEAD 一致。
- `capability:audit`、`arch:test` 均作为发布门禁的一环。

## 7. 可回归

| 门禁 | 结果 |
|---|---|
| 后端 `php artisan test` | **1092 passed / 50,751 assertions** |
| 后端 `pint --test` | PASS（1132 files） |
| 后端 `phpstan analyse` | 0 errors |
| `capability:audit` | OK |
| `arch:test` | Module boundaries OK（4 条既有非阻断 warning） |
| 前端 `typecheck` | PASS |
| 前端 `lint` | PASS（0 warnings） |
| 前端 `test:unit` | **238 passed / 32 files** |
| `openapi:check` | PASS |
| `v11:gate` | 见第 9 节 |

## 8. 不留尾巴

- 一份 24 Task 的执行台账：`.superpowers/sdd/v11-gap/ledger.md`（含每个 Task 的评审结论与修复）。
- legacy 路由台账由真实路由表生成，不是手写：**314 operations / 145 kernel / 169 legacy**。
  新增路由不得“出生即 legacy”（冻结清单 + 测试阻断），已做变异验证。
- 剩余 legacy 均登记了迁移 Task（B1–B5 / C1–C4 / D1–D4 / E3），无未登记缺口。

## 9. 生产上线门禁（`pnpm v11:gate`）

八项底线一条命令串行执行，任一失败即非零退出，并写 `evidence/v11-production-gate-<stamp>.log`：

1. `gap-zero` — 63 缺口清零
2. `openapi` — 契约有效且每个操作有 schema
3. `capability` — 每路由有守卫、每码有授予
4. `negative-tests` — 12 集群负测矩阵
5. `migration` — 前向迁移空库演练
6. `real-stack` — 真实栈 160 页 + 发布可追溯
7. `release-trace` — `/v1/meta` commit 与部署一致
8. `coverage` — 覆盖矩阵可从注册表重建

## 10. 结论

**63 项功能缺口已全部实现为真实 API，前后端、契约、权限负测、迁移演练与生产门禁均通过。**

发布前仍需由运维确认（不属代码阻断项）：
1. 生产 `.env` 使用独立密钥，`APP_ENV=production`、`APP_DEBUG=false`。
2. 按 ADR-0025 设定 `IAM_MFA_ENABLED`，并保留相应审计。
3. 以 `--expect-commit <SHA>` 校验部署产物。
