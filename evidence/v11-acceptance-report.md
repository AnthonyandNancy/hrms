# V1.1 生产补全验收报告

- 状态：**代码与本地/真实栈门禁完成；联合审计结论见第 8 节**
- 依据规范：`docs/superpowers/specs/2026-11-25-v11-production-completion-design.md`
- 依据计划：`docs/superpowers/plans/2026-11-25-v11-production-completion-implementation-plan.md`
- 页面清单：`docs/audit/2026-11-25-v11-page-inventory.md`（140 业务页）
- 访问蓝图：`docs/audit/2026-11-25-v11-page-access-blueprint.md`（14 平台模块 + 6 基础页）
- ADR：0024 超管全权与 P3 审计、0025 内网 MFA 姿态、0026 页面覆盖注册表、0027 页面权限与角色分配
- 覆盖矩阵：`docs/audit/v11-coverage-matrix.md`（机器生成）

> 本报告只陈述已实际执行的命令与原始输出。任何未执行项都必须保留为 `PENDING`，不得提前写“通过”。

## 1. 交付范围

| 交付物 | 数量 | 证据 |
|---|---:|---|
| 业务页面（17 菜单组） | 140 | 注册表 160 条、`--require-mature` 门禁 |
| 平台模块 | 14 | `PLATFORM_COMPONENT_KEYS` 36（含 22 业务子页） |
| 基础页 | 6 | `base/*` 组件映射 |
| 注册表总条目 | **160** | `node scripts/audit_route_coverage.mjs --require-mature` |
| 工作包 | **26** | `docs/audit/v11-coverage-matrix.md` |
| 角色模板 | **14** | `CapabilityCatalogTest`、`iam:sync-capabilities` |
| 能力码 | **112** | `CapabilityCatalogCoverageTest` |
| OpenAPI 路径 / 操作 | **163 / 193** | `openapi-lint: OK (163 paths, 193 operations, 3.1.0)` |

## 2. 页面覆盖（机器事实）

`node scripts/audit_route_coverage.mjs --require-mature` 的结果：

```json
{
  "registry": {
    "entries": 160,
    "layers": { "business": 140, "platform": 14, "base": 6 },
    "missingRenderers": 0,
    "matureViews": 160,
    "fallbackViews": 0
  },
  "coverage": { "business": "140/140", "platform": "14/14", "base": "6/6" },
  "mode": "release",
  "failures": []
}
```

`docs/audit/v11-coverage-matrix.md` 由 `scripts/generate_coverage_matrix.mjs` 生成，逐页列出路由、能力码、成熟度与 OpenAPI 缺口。当前 29 处页面声明的 API 缺口均为**显式“后端接口待补”状态**，未伪造数据。

## 3. 本地门禁（已执行）

证据日志：`evidence/v11-local-gates-20261125T071638Z.log`

| 门禁 | 命令 | 结果 |
|---|---|---|
| 后端测试 | `cd backend && php artisan test` | **901 passed / 45,360 assertions** |
| 代码风格 | `vendor/bin/pint --test` | **passed** |
| 静态分析 | `vendor/bin/phpstan analyse` | **No errors** |
| 权限默认拒绝 | `php artisan capability:audit` | **OK（无警告）** |
| 模块边界 | `php artisan arch:test` | **OK（4 条非阻断提示）** |
| 前端类型 | `cd frontend && pnpm typecheck` | **exit 0** |
| 前端 lint | `pnpm lint` | **exit 0** |
| 前端单测 | `pnpm test:unit` | **168 passed / 29 files** |
| 生产构建 | `VITE_API_MODE=http pnpm build` | **built in 13.06s** |
| 契约生成 | `pnpm openapi:check` | **is current** |
| 契约 lint | `node deploy/scripts/openapi-lint.mjs` | **OK (163 paths, 193 operations)** |
| Redocly | `pnpm openapi:validate` | **valid**（2 条非阻断 warning） |

## 4. 授权与业务负测证据（已执行）

- `evidence/v11-authorization-negative-20261125T071526Z.log`：锚点/范围拒绝、字段级拒绝、跨法人隔离、超管全权与 P3 审计、MFA 与会话撤销、无有效任职 409 —— **6/6 组通过**。
- `evidence/v11-concurrency-idempotency-20261125T071605Z.log`：审批并发、幂等重放与冲突、入职效果去重、加班唯一结算、培训重复报名、考勤月结锁定、薪资状态机 —— **7/7 组通过**。
- 真实栈 live 负测（最终 SHA `08f409a`）：`demo.employee` 访问员工目录、用户管理、跨法人员工均 **403**；`demo.hr` 访问工资条与薪资批次均 **403**，返回 `CAPABILITY_DENIED` 与具体 capability/anchor。后者符合 ADR-0027 的职责分离：`HR_ADMIN` 不含 P3 薪资读取，薪资由 `PAYROLL_SPECIALIST`/`PAYROLL_APPROVER` 持有。

## 5. 真实栈证据（已执行，SHA 2f68759）

- 总日志：`evidence/v11-real-stack-20261125T070847Z.log`
- 160 页真实栈路由审计：**1 passed (2.4m)**，零未解释 5xx、零非预期 not-found、零错误边界。
- release traceability：`/v1/meta` 返回真实 `commit/build_time/spa_build_sha`，smoke 校验 commit 一致。
- MySQL 备份恢复：**117 张表、0 差异**（`evidence/mysql-backup-restore.txt`）。
- 读路径压测：`evidence/load-probe-2026-11-25T07-01-35-079Z.json`，60 请求 / 并发 6 / **0 失败 / p95 251.45ms**。

> 该次真实栈证据对应提交 `2f68759`。最终 release SHA 复跑结果见第 7 节。

## 6. 已确认的真实缺口（非伪造）

页面已用显式“后端接口待补”状态呈现，不得在验收中算作“业务完成”：

- 劳动合同：无集合台账、合同变更/模板/附件子接口；台账按员工逐个组合并设 20 人上限并显示 partial 横幅。
- 岗位：无任职资格、职级目录、岗位员工子接口。
- 员工异动：无借调专用命令；档案变更提交按钮未接入。
- 绩效：指标、目标、360 反馈、校准、结果、报表无后端读接口。
- 招聘：需求审批/看板/预算/报表，候选人导入/漏斗/评估/沟通/人才库/转员工结果无接口；候选人服务端筛选有限。
- 培训：签到、考试、反馈、学习档案、汇总报表无接口；无报名列表 GET。
- 平台：`POST /v1/roles`、菜单写、数据范围写、字段策略写、字典/参数、福利、通知投递日志无接口。
- 报表：`PAYROLL_COST`、`RECRUITMENT_FUNNEL` 后端明确返回 **503 语义不可用**（“投影未发布，不估算”）；页面已渲染专门“报表数据尚未发布”状态，审计将此类 503 视为预期业务状态，其余 5xx 仍判失败。

## 7. 最终 release SHA 复跑（已完成）

- 最终 release commit：**`08f409a64d41d433625f12624728d2b02ea7dca5`**
- 镜像：`factoryhr/php-app:v11-08f409a`、`factoryhr/frontend:v11-08f409a`
- `/v1/meta`：`commit=08f409a...`、`spa_build_sha=08f409a...`、`build_time=2026-11-25T07:23:02Z`
- 最终真实栈日志：`evidence/v11-real-stack-20261125T072544Z.log`
- 结果：**5/5 门禁通过**（readiness、release traceability、smoke、真实登录与未认证拒绝、160 页路由审计、MySQL 117 表备份恢复零差异）
- 最终压测：**120 请求 / 并发 12 / 0 失败 / p95 69.85ms / 194.49 RPS**
- 说明：该栈使用 `APP_ENV=local` 与合成 demo 数据；生产环境需按 ADR-0025 设置 `IAM_MFA_ENABLED` 与真实密钥，并以 `--expect-commit` 校验发布 SHA。

## 8. 结论与发布条件

**代码、契约、权限负测、页面覆盖与真实栈验收均已通过**，release SHA 为 `08f409a`。以下条件必须在生产发布前由运维确认，不构成本轮代码阻断：

1. 生产 Compose 使用独立 `.env`（禁止示例密钥），设置 `APP_ENV=production`、`APP_DEBUG=false`、独立 `APP_KEY`。
2. 按 ADR-0025 决定 `IAM_MFA_ENABLED`；若关闭，必须保留 `install.mfa_skipped` 审计并在内网边界评审中签字。
3. 生产备份使用 `deploy/scripts/mysql_backup_restore.sh` 对实际库执行，并将日志归档到发布记录。
4. 部署命令必须传入 `GIT_SHA`/`BUILD_TIME`，并用 `deploy/scripts/smoke.sh --expect-commit <SHA>` 校验。
5. 第 6 节列出的后端缺口继续以“后端接口待补”呈现；补齐后需重跑 160 页审计与对应负测。

**结论：V1.1 功能交付与验收门禁通过，可进入发布评审。**
