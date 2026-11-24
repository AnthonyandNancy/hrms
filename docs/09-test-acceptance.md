# 测试与验收

> 本文件把实施计划 §05 的测试金字塔与量化门禁，落成本仓库**可执行的命令、真实的通过标准与已知缺口**。
> 结论与证据见 [`../evidence/acceptance-report.md`](../evidence/acceptance-report.md)；
> 审计过程见 [`11-joint-audit-history.md`](11-joint-audit-history.md)。

## 1. 一条命令跑全部门禁

```bash
bash scripts/verify_release.sh
```

它按顺序执行发布门禁，写出带时间戳的日志到 `evidence/logs/verify-<UTC>.log`，并在结尾打印
`PASS=<n> FAIL=<n> SKIP=<n>`。任何 FAIL 使脚本以非零退出。

**SKIP 不等于通过。** 需要真实环境或数据的门禁（工资平行、迁移彩排等）在缺少前置条件时
必须显式 SKIP 并注明原因，不得静默视为通过。

## 2. 测试金字塔与落点

| 类型 | 本仓库落点 | 命令 | 当前规模 |
|---|---|---|---|
| 单元 | `backend/tests/Unit`（金额、时间、账本、工资域、权限枚举） | `php artisan test --testsuite=Unit` | 259 tests |
| 契约 | `backend/tests/Contract` + OpenAPI lint | `php artisan test --testsuite=Contract`、`node deploy/scripts/openapi-lint.mjs`、`pnpm openapi:validate` | 18 tests |
| 功能 | `backend/tests/Feature`（API 成功/失败/边界） | `php artisan test --testsuite=Feature` | 395 tests |
| 集成 | `backend/tests/Integration`（跨模块契约、as-of 一致、UAT 链路） | `php artisan test --testsuite=Integration` | 21 tests |
| 架构 | `backend/tests/Architecture` + `arch:test` | `php artisan arch:test` | 29 tests |
| 权限负测 | `endpoint_matrix.php`（134 端点）+ `capability:audit` | `php artisan capability:audit` | 134 条 |
| 时间旅行 | Fake/Frozen Clock、as-of、DST、跨夜 | `php artisan test --filter=AsOf`、`--filter=Shift` | 见 E-09 |
| 账本对账 | Artisan 命令 | `php artisan ledger:reconcile` | E-10 |
| 工资平行 | Artisan 命令 + 导出脚本 | `php artisan payroll:parallel-check` | E-11 |
| 迁移彩排 | Artisan + shell | `php artisan migrate:rehearsal`、`bash deploy/scripts/migration_rehearsal.sh` | E-12 |
| E2E | Playwright（mock + 真实后端两套） | `pnpm test:e2e`、`pnpm exec playwright test --config playwright.real.config.ts` | 11 + 8 |
| 前端单元 | Vitest | `pnpm test:unit` | 30 tests |

合计后端 **722 tests / 35,728 assertions**（Unit 259 + Feature 395 + Contract 18 + Integration 21 + Architecture 29；0 failure / 0 error / 0 skipped）。

## 3. 门禁清单（当前实现）

| # | 门禁 | 命令 | 阻断 |
|---|---|---|---|
| 1 | PHP 语法 | `find … -name '*.php' \| xargs -n1 php -l` | 是 |
| 2 | 后端测试 | `php artisan test` | 是 |
| 3 | 代码风格 | `vendor/bin/pint --test` | 是 |
| 4 | 静态分析 | `vendor/bin/phpstan analyse`（level 6） | 是 |
| 5 | 能力覆盖 | `php artisan capability:audit` | 是 |
| 6 | 架构边界 | `php artisan arch:test` | 是 |
| 7 | 契约测试 | `php artisan test --filter=OpenApiContractTest` | 是 |
| 8 | 前端类型 | `pnpm typecheck` | 是 |
| 9 | 前端 Lint | `pnpm lint` | 是 |
| 10 | 前端单测 | `pnpm test:unit` | 是 |
| 11 | 前端构建 | `pnpm build` | 是 |
| 12 | Mock E2E | `pnpm test:e2e` | 是 |
| 13 | OpenAPI Lint | `node deploy/scripts/openapi-lint.mjs` | 是 |
| 14 | 备份恢复 | `bash deploy/scripts/backup_restore_smoke.sh` | 是 |
| 15 | 迁移彩排 | `bash deploy/scripts/migration_rehearsal.sh` | 是 |
| 16 | 回滚演练 | `bash deploy/scripts/rollback_drill.sh` | 是 |
| 17 | 分域迁移包装器 ×4 | `php deploy/scripts/migrate_{organizations,people,leave_opening,payroll_ytd}.php` | 是 |
| 18 | 工资对账 | `php artisan payroll:reconciliation` | 是（有批次时） |
| 19 | 工资平行 | `php artisan payroll:parallel-check` | 是（有批次时） |

当前汇总：`PASS=23 FAIL=0 SKIP=0`。

## 4. 量化标准对照（计划 §5.2）

| 计划门禁 | 量化标准 | 当前状态 | 说明 |
|---|---|---|---|
| 契约 | lint 0 错误；100% 受保护端点入契约 | **达成** | 125 paths / 139 operations；Redocly 3 条非阻断 warning |
| 权限负测 | 每端点 ≥4 类拒绝；高危 ≥8 类 | **达成（元数据）** | 134 端点全部登记拒绝类别；高危端点 8 类。真实重放见 §5 |
| 时间旅行 | T-1/T/T+1、跨夜、跨年、时区 | **达成** | `AsOfQueryTest`、`AsOfConsistencyTest`、`ShiftWindowTest`、`OvernightShiftTest` |
| 身份与会话 | SSO + 本地兜底；MFA 强制；撤权 403 | **部分** | 本地登录、MFA step-up、撤权、CSRF 已测；**真实 IdP/SSO 为 GATE** |
| 账本平衡 | 未解释差异=0；重复 10 次不重复扣减 | **达成** | `ledger:reconcile` 0 差异；`LeaveConcurrencyTest`、`ReversalTest` |
| 工资平行 | ≥2 完整周期；100% 人头；净额 ±0.01；差异=0 | **仅机制达成** | 1 个周期、4 人头、0 差异；**≥2 周期与真实旧系统导出为 GATE-06** |
| 迁移彩排 | 3 次全量；源记录 100% 可追溯；孤儿=0 | **部分** | SHADOW 下 6 域 APPLIED + reconcile；**3 次生产式彩排为 GATE-07** |
| 混沌 | ≥12 场景；RTO≤2h、RPO≤15min | **未达成** | 仅回滚/备份脚本；**混沌测试为 GATE** |
| UAT | P0 100%；Sev1/2=0；签字 100% | **自动化达成，签字 GATE** | UAT-01–13 均有自动化映射；**业务签字为 GATE-08** |
| 性能 | 300 并发 P95<500ms | **未执行** | 无压测环境；**为 GATE** |
| 安全 | 无高危；无默认口令；日志无明文 | **达成（本地）** | composer audit、gitleaks、日志脱敏、口令不入库；**SAST/渗透为 GATE** |
| 合规 | 字段五元组；保留矩阵；法务保全 | **部分** | 字段档位与保留期命令已实现；**法务签字与跨境数据流评估为 GATE** |

## 5. 缺陷分级与修复 SLA

沿用计划 §5.4：

| 等级 | 定义 | SLA | 发布影响 |
|---|---|---|---|
| Sev1 | 数据泄露、工资错发、系统不可用 | 4h 内缓解 | 阻断，必要时回滚 |
| Sev2 | 核心流程不可用、账本/权限错误 | 1 个工作日 | 阻断 |
| Sev3 | 非核心功能错误、体验问题 | 当前 sprint | 可带计划发布 |
| Sev4 | 文案、样式 | 排期 | 不阻断 |

本仓库在联合审计中记录并修复的缺陷（含 2 个 Sev2、1 个架构门禁失效、1 个合规缺陷）见
`11-joint-audit-history.md`。**没有未关闭的 Sev1/Sev2。**

## 6. 测试数据策略

- 所有种子与测试使用**合成数据**；姓名、证件号、手机号为明显虚构值。
- 迁移夹具在 `backend/database/migration-fixtures/`，`known-good/` 为一致的 12 人数据集，
  `broken/` 为每个校验器一条刻意损坏的记录。
- 时间通过可注入 `Clock`（`FrozenClock`）固定；随机与 ID 生成可重放。
- 真实后端 E2E 需要操作者提供 `HRMS_DEMO_PASSWORD`；未提供时整套用例**显式 skip**，不伪装通过。
- 工资平行**不手工改数对齐**；`scripts/payroll_old_export.php` 生成的是合成对照并主动打印警告。

## 7. 复现要点

```bash
# 后端全部
cd backend && php artisan test

# 单类门禁
php artisan test --filter=Reporting
php artisan test --filter=TimeWorkflowIntegrationTest
php artisan ledger:reconcile --as-of=2026-12-31
php artisan capability:audit && php artisan arch:test

# 前端全部
cd frontend
pnpm typecheck && pnpm lint && pnpm test:unit && pnpm build && pnpm test:e2e

# 真实后端 E2E（需先启动 8000 与演示数据）
export HRMS_DEMO_PASSWORD='<the password you seeded>'
pnpm exec playwright test --config playwright.real.config.ts

# 端到端发布门禁
cd .. && bash scripts/verify_release.sh
```

## 8. 明确的未完成项

| 项 | 原因 | 责任 |
|---|---|---|
| 真实 IdP/OIDC 联调 | 无外部身份提供方 | 安全 / 平台 |
| ≥2 个完整周期的真实工资平行 | 无旧系统导出 | 财务 / QA |
| 三次生产式迁移彩排（含冻结窗口） | 仅 SHADOW 演练 | 数据 / QA |
| 混沌测试 ≥12 场景、RTO/RPO 实测 | 无故障注入环境 | DevOps |
| 性能压测（300 并发 / 10k 员工） | 无压测环境 | DevOps / 后端 |
| 渗透测试与 SAST 结果 | 需外部环境，CodeQL 仅在 CI | 安全 |
| UAT-01–13 业务签字 | 需业务方 | 业务 |
| S-01–S-08 组织签认 | 需发起人与责任人 | 发起人 |
