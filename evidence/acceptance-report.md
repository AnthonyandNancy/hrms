# HRMS V1.0 联合审计与验收报告

> 本报告按 `docs/10-joint-audit-acceptance.md` 的维度与证据编号填写。原则：**证据优先于声明；未执行的测试不标记通过；组织签认与代码通过分开记录。**

| 项目 | 值 |
|---|---|
| 代码基线 | `93d2fd861b0bed161292138550114e06fc25caff` |
| 集成提交 | `321a5d0`（后端）、`600c30c`（前端与契约）、`93d2fd8`（ADR 与运维文档） |
| 审计时间（UTC） | 2026-11-23T21:15Z |
| 环境 | Windows + git-bash；PHP 8.4.28；Laravel 13；SQLite（本地/测试）；MySQL 8.4 仅限 Compose 目标 |
| 审计对象 | 参考实现（reference implementation），非 30 周生产切换完成态 |
| 复现入口 | `bash scripts/verify_release.sh` → `evidence/logs/verify-release.txt` |

## 1. 结论

**`ACCEPTED_WITH_GATES`**

- 全部 16 项可执行发布门禁通过（`PASS=16 FAIL=0 SKIP=0`），无 Sev1、无未关闭 Sev2。
- 联合审计期间发现并修复 5 类真实缺陷（2 个 Sev2、2 个体验/正确性缺陷、1 个合规缺陷）。其中 Sev2 均有失败测试先行复现并由回归测试锁定；合规缺陷以构建产物检索确认修复。
- 组织签认（S-01–S-08）、真实身份源、银行/税务申报、工资条 PDF、HA、渗透测试与真实迁移平行运行仍为 **GATE**，本仓库不伪造其通过。

结论规则来自 `docs/10-joint-audit-acceptance.md` §5：所有阻断证据通过但组织/外部门禁未完成 → `ACCEPTED_WITH_GATES`。

## 2. 门禁总览

由 `scripts/verify_release.sh` 一次连续执行，日志 `evidence/logs/verify-release.txt`：

| # | 门禁 | 命令 | 结果 |
|---|---|---|---|
| 1 | PHP 语法 | `find app database routes tests -name '*.php' \| xargs -n1 php -l` | PASS |
| 2 | 后端测试 | `php artisan test` | PASS |
| 3 | 代码风格 | `vendor/bin/pint --test` | PASS |
| 4 | 静态分析 | `vendor/bin/phpstan analyse --memory-limit=1G` | PASS |
| 5 | 能力覆盖 | `php artisan capability:audit` | PASS |
| 6 | 架构边界 | `php artisan arch:test` | PASS |
| 7 | 契约测试 | `php artisan test --filter=OpenApiContractTest` | PASS |
| 8 | 前端类型 | `pnpm typecheck` | PASS |
| 9 | 前端 Lint | `pnpm lint` | PASS |
| 10 | 前端单测 | `pnpm test:unit` | PASS |
| 11 | 前端构建 | `pnpm build` | PASS |
| 12 | Mock E2E | `pnpm test:e2e` | PASS |
| 13 | OpenAPI Lint | `node deploy/scripts/openapi-lint.mjs` | PASS |
| 14 | 备份恢复 | `bash deploy/scripts/backup_restore_smoke.sh` | PASS |
| 15 | 迁移彩排 | `bash deploy/scripts/migration_rehearsal.sh` | PASS |
| 16 | 回滚演练 | `bash deploy/scripts/rollback_drill.sh` | PASS |

汇总行：`PASS=16 FAIL=0 SKIP=0`。

## 3. 证据矩阵 E-01–E-17

| 编号 | 证据 | 结果 | 证据文件 / 说明 |
|---|---|---|---|
| E-01 | 架构边界报告 | **PASS** | `evidence/logs/arch-test.txt`：`Module boundaries OK`，16 条非阻断 ownership 警告（见 §6 已知限制） |
| E-02 | 能力覆盖报告 | **PASS** | `evidence/logs/capability-audit.txt`：`Capability audit OK`，受保护路由全部有 capability，公共路由仅 5 条显式白名单 |
| E-03 | 后端测试报告 | **PASS** | `evidence/logs/backend-test-current.txt` 与 `junit-current.xml`：**705 passed / 35,666 assertions / 0 failure / 0 error / 0 skipped** |
| E-04 | 静态分析与风格 | **PASS** | `evidence/logs/pint.txt`（887 文件 PASS）、`evidence/logs/phpstan.txt`（`[OK] No errors`，level 6） |
| E-05 | 契约校验 | **PASS** | `evidence/logs/openapi-lint.txt`（125 paths / 139 operations / 3.1.0）、`evidence/logs/openapi-validate.txt`（Redocly valid，3 条非阻断 warning）、契约测试 PASS |
| E-06 | 前端质量 | **PASS** | `frontend-typecheck.txt`、`frontend-lint.txt`、`frontend-unit.txt`（30 passed）、`frontend-build.txt` |
| E-07 | E2E 报告 | **PASS** | Mock：`frontend-e2e-mock.txt`（11 passed）；真实后端：`frontend-e2e-real.txt`（8 passed，1.8m） |
| E-08 | 权限负测矩阵 | **PASS** | `backend/tests/Authorization/endpoint_matrix.php` 覆盖 **134** 条受保护端点，`capability:audit` 无缺失告警；负测见 §4 |
| E-09 | 时间旅行报告 | **PASS** | 半开区间 `AsOfQueryTest`/`DateRangeTest`；T-1/T/T+1 `AsOfConsistencyTest`；DST 与跨夜 `ShiftWindowTest`、`OvernightShiftTest` |
| E-10 | 假勤账本对账 | **PASS（空集）** | `evidence/logs/ledger-reconcile.txt`：`Differences: 0`。⚠ 演示租户当前无 leave type/ledger 记录，对账为 0 行；算法正确性由 `LeaveLedgerBalanceTest`、`ReversalTest`、`LeaveConcurrencyTest` 覆盖 |
| E-11 | 工资计算与平行报告 | **PARTIAL → GATE** | 单元与集成：`CumulativeWithholdingTest`、`ProrationRoundingTest`、`CorrectionRunTest` 等全绿；`payroll-parallel-check.txt` 退出码 **2 = NOT_EXECUTED**（无旧系统导出文件），**不视为通过** |
| E-12 | 迁移彩排与对账 | **PASS（SHADOW）** | `migration-rehearsal.txt`、`migration-rehearsal-script.txt`：6 域 `APPLIED`/`reconcile=PASS`，target=SHADOW |
| E-13 | 备份恢复与回滚演练 | **PASS（本地范围）** | `backup-restore.txt`（sha256 一致）、`verify-release.txt` 中 rollback drill PASS（2.3s / 3600s 预算）。MySQL PITR、feature-flag 回退、路由切换为 NOT_EXECUTED |
| E-14 | 安全扫描 | **PASS（本地）+ GATE（SAST）** | `composer-audit.txt`：无已知漏洞；`gitleaks.txt`：106 commits，`no leaks found`；SAST 由 `.github/workflows/codeql.yml` 在 GitHub 执行，本地无法证明 |
| E-15 | 审计样本 | **PASS** | 登录/失败/锁定：`LoginSecurityTest`；撤权：`SessionRevocationTest`；导出：`ExportScopeTest`；工资封存：`PayrollRunLifecycleTest`；break-glass：`BreakGlassTest`；审计只追加：`AuditAppendOnlyTest` |
| E-16 | UAT 记录 | **GATE** | 自动化映射见 §5；UAT-01–13 **业务签字未完成**，不得标记通过 |
| E-17 | 组织签认 | **GATE** | S-01–S-08 **未签认**，按 `docs/10-joint-audit-acceptance.md` 阻断对应 release |

## 4. 负测与授权证据（E-08）

| 维度 | 证据 | 结果 |
|---|---|---|
| 默认拒绝 | `DenyByDefaultTest`：401 信封、无能力 403、全部受保护路由有 capability 元数据 | PASS |
| 失败关闭 | `FailClosedAuthorizerTest`：未知能力一律拒绝 | PASS |
| 七类 Anchor | `AnchorScopeTest`：SELF / MANAGED / ORG_UNIT / ORG_SUBTREE / CASE / PAYROLL_RUN / LEGAL_ENTITY 逐一验证，含跨法人、跨租户 | PASS |
| 字段档位 P0–P3 | `FieldProjectionTest`、`FieldDenialHttpTest`：P3 永不输出/排序/过滤；缺失字段覆盖返回 404 而非部分投影 | PASS |
| IDOR | `FieldDenialHttpTest`、`ExportScopeTest`：改 ID / 换 token 返回 404，不泄露存在性 | PASS |
| 会话与撤权 | `SessionRevocationTest`：角色/绑定/密码/离职变更即时使旧会话 401；`MfaTest`：特权能力强制 step-up；`BreakGlassTest`：双人 + 30 分钟 + 理由不回落 | PASS |
| CSRF / Cookie | `CsrfTest`、`LoginSecurityTest`、真实 E2E "cookie is HttpOnly and CSRF not in localStorage" | PASS |

**本次修复的授权缺陷（Sev2 级影响面）**：SELF 路由中间件此前用"调用者自身行"作为锚点求值，导致持有 `LEGAL_ENTITY`/`ORG_UNIT` 绑定的合法角色在 `/v1/employees`、`/v1/org-units/tree`、`/v1/me/workspace` 等入口被 403。修复为三方法语义（`permits()` 特性门、`allows()` 具体资源门、`constrain()` 行范围），已在 ADR-0004 固化并有回归测试。

## 5. UAT 场景映射

| UAT | 场景 | 自动化证据 | 状态 |
|---|---|---|---|
| UAT-01 | 新员工入职到首月工资 | `Integration/UatOnboardingToFirstPayrollPreparationTest`、`OnboardingEffectTest`、`OnboardingDedupTest` | 自动化 PASS / 人工 GATE |
| UAT-02 | 调岗未来生效 | `TransferFutureEffectTest`（今日视图不变）、`AsOfConsistencyTest` | 自动化 PASS / 人工 GATE |
| UAT-03 | 合同续签 | `ContractRenewalTest`（新版本生效，旧合同按 as-of 可读） | 自动化 PASS / 人工 GATE |
| UAT-04 | 跨夜班次打卡 | `OvernightShiftTest`（跨夜、春令时、秋令时、无 DST 时区） | 自动化 PASS / 人工 GATE |
| UAT-05 | 请假占用/撤销/结算 | `LeaveLedgerBalanceTest`、`ReversalTest`、`LeaveConcurrencyTest`、`TimeEndpointsTest` | 自动化 PASS / 人工 GATE |
| UAT-06 | 工资封存后更正 | `CorrectionRunTest`（仅增量、原批次不变、YTD 可对账） | 自动化 PASS / 人工 GATE |
| UAT-07 | 离职结算 | `OffboardingOffboardTest`（检查单 + 同事务撤权）、`OffboardingChecklistTest` | 自动化 PASS / 人工 GATE |
| UAT-08 | 跨公司调动 | `CrossLegalEntityTest`、`TransferFutureEffectTest`（结束旧 Employment 建新 Employment） | 自动化 PASS / 人工 GATE |
| UAT-09 | 权限越权 | §4 全部负测 + 真实 E2E 员工访问 HR 目录被拒 | 自动化 PASS / 人工 GATE |
| UAT-10 | 仪表盘与下钻 | `Reporting/DashboardConsistencyTest`（同口径、同授权谓词）、`Reporting/ExportScopeTest`、真实 E2E 面板数据 | 自动化 PASS / 人工 GATE |
| UAT-11 | 累计预扣与舍入 | `CumulativeWithholdingTest`（跨月、专项扣除、负数结转、年结转）、`ProrationRoundingTest` | 自动化 PASS / 人工 GATE |
| UAT-12 | 身份与会话 | `MfaTest`、`BreakGlassTest`、`SessionRevocationTest`、`LoginSecurityTest` | 自动化 PASS / 人工 GATE（真实 IdP 为 GATE） |
| UAT-13 | 保留期与删除 | `RetentionTest`（法务保全阻断清除、dry-run 默认、永久类不清除） | 自动化 PASS / 人工 GATE |

## 6. 审计期间发现并修复的缺陷

| # | 缺陷 | 严重度 | 复现 | 修复 | 回归证据 |
|---|---|---|---|---|---|
| 1 | Time 以 `LEAVE_REQUEST` / `ATTENDANCE_CORRECTION` 建 Case，但模板目录只有 `leave.request` 等点号编码，真实环境请假与考勤更正**必然失败**；测试用内存 fake 掩盖了该路径 | **Sev2** | `tests/Feature/Time/TimeWorkflowIntegrationTest.php`（修复前 3 项失败，报 "No published workflow template exists for this case type."） | 新增 `CaseTemplateCatalog` 显式映射；补充 `attendance.correction` 种子；未映射类型仍失败关闭 | `TimeWorkflowIntegrationTest` 5 passed / 18 assertions；`CommandsTest` 14 passed |
| 2 | `MANAGER_OF_SUBJECT` 审批人解析在生产环境恒为 `null`，导致所有经理审批 Case 无法提交（"Approval node has no eligible assignee."） | **Sev2** | 同上测试中的 manager 用例（修复前失败） | 新增 People 拥有的 `EmploymentManagerDirectory` 契约与 Eloquent 实现，IAM 审批人目录改为委派该契约，不跨模块读表 | 同上，断言 `workflow_tasks.assignee_user_id = 20` |
| 3 | 仪表盘"待我审批 / 合同到期 / 最近公告"三个面板在真实模式恒为空（前端硬编码空数组）；且 payroll/recruitment 的不可用原因文字声称"表不存在"，与事实不符 | Sev3 | 真实后端 HTTP 探测；`DashboardPanelsTest` | 后端从与指标同一授权投影生成三个有界面板；更正不可用原因文案 | `DashboardPanelsTest` 3 passed；真实 E2E notice 面板断言 |
| 4 | HR 角色缺少 `engagement.notice.read/manage` 与 `reporting.export`，公告与报表页 403 | Sev3 | 真实 API 探测 | 补授能力；payroll run 能力**仍不给 HR**（职责分离） | `capability:audit` 0 缺失；E2E HR 页面全通 |
| 5 | 前端登录页与构建产物内置可用演示口令，真实 E2E 以其为兜底默认值（违反 AGENTS.md 规则 8） | **合规缺陷** | `grep -rn 'Demo!<redacted>' frontend/src frontend/dist` 命中 | 登录页密码留空；真实 E2E 要求 `HRMS_DEMO_PASSWORD`，缺失则 skip；README 说明来源 | 重建后构建产物 0 命中；30 unit / 11 mock E2E / 8 real E2E 全绿 |

## 7. 已知限制（如实记录，不视为通过）

| 项 | 说明 | 影响 |
|---|---|---|
| 工资平行运行 | `payroll:parallel-check` 需 `--old-file` 旧系统导出；本地无该文件，退出码 2 = `NOT_EXECUTED` | E-11 无法给 PASS；R3 工资平行验收仍为 GATE |
| 银行/税务申报 | 仅提供 CSV 适配器与回执登记，无真实银行/税务接口 | 生产启用为 GATE |
| 工资条 PDF | 未实现 PDF 渲染 | 生产交付为 GATE |
| 法定参数 | DEMO 参数为合成值，不构成法律意见；每法人仅一套 SI/HF 规则 | 上线前需按地区签字确认 |
| 仪表盘待审批口径 | Workflow 的 `ReportingApprovalDirectory` 是租户级、不含 assignee 字段，面板按调用者授权范围过滤但无法区分"分配给我" | 已在 `DashboardService` 注释与 ADR 记录；需 Workflow 提供 assignee 维度投影 |
| 公告可见范围 | `NoticeWorkspaceDirectory` 返回 ALL 公告及本人已确认项，不展开 ROLE/ORG 受众 | 面板偏保守（只会少、不会多）；应在 Engagement 内完善 |
| 演示数据 | `demo:seed` 为 SHADOW 模式：6 域 APPLIED 但不并入业务表；演示租户无 leave type、无工资运行 | E-10/E-11 在演示租户上为空集；不据此推断生产正确性 |
| 迁移彩排 | 仅在 SHADOW 目标完成 6 域对账 | 生产冻结窗口三轮彩排仍为 GATE |
| 回滚演练 | 脚本证明快照/恢复/完整性/时限；MySQL PITR、feature-flag 回退、路由切换以 `NOT_EXECUTED` 明示 | 生产回滚演练仍为 GATE |
| 架构警告 | `arch:test` 16 条非阻断警告（`RetentionService` 动态表名、`feature_flags` 等未登记 owner） | 不阻断，但已登记为 ADR-0015 复审触发条件 |
| SAST | CodeQL 仅在 GitHub Actions 运行 | 本地证据不包含 SAST 结果 |
| 演示口令的残留历史 | 该口令曾出现在提交 `600c30c`/`a565a04` 的文件内容中，已在 `a565a04` 从工作树移除、并在 `388f6f1` 从审计文档中脱敏；因仓库无 remote 且存在 12 个 worktree 与 15+ 分支，未做历史重写 | 对该值视为**已泄露并应轮换**：任何按本仓库部署的环境都必须使用全新的 `DEMO_SEED_PASSWORD`，不得复用该值。若将来添加 remote，须在首次推送前重写历史或强制轮换 |

## 8. 组织门禁（GATE，不得标记通过）

| 编号 | 事项 | 责任 | 状态 |
|---|---|---|---|
| S-01–S-08 | 组织签认（薪酬口径、法定参数、数据迁移、权限矩阵、保留期、切换、UAT、Go/No-Go 等） | 发起人 / HR / 财务 / 法务 / 安全 | **未签认** |
| GATE-01 | 真实 IdP/OIDC 与 SSO 联调 | 安全 / 平台 | 未执行 |
| GATE-02 | 银行代发、税务申报与回执 | 财务 | 未执行 |
| GATE-03 | 工资条 PDF 与员工送达 | 产品 / 财务 | 未实现 |
| GATE-04 | 生产 HA、KMS、MySQL PITR、RTO/RPO 演练 | DevOps | 未执行 |
| GATE-05 | 第三方渗透测试与整改 | 安全 | 未执行 |
| GATE-06 | 真实旧系统工资平行运行（差异=0） | 财务 / QA | 未执行（退出码 2） |
| GATE-07 | 三轮生产式迁移彩排与冻结窗口 | 数据 / QA | 仅 SHADOW 完成 |
| GATE-08 | UAT-01–13 业务签字 | 业务 | 未签字 |

## 9. 复现方法

从干净检出执行：

```bash
export PATH="/d/code/ai/factoryHR/.tools/php:$PATH"
bash scripts/verify_release.sh          # 16 gates, writes evidence/logs/verify-<stamp>.log
```

单独复现关键证据：

```bash
cd backend
php artisan test                        # 705 tests
php artisan test --filter=TimeWorkflowIntegrationTest
php artisan test --filter=Reporting
php artisan ledger:reconcile --rebuild
php artisan capability:audit && php artisan arch:test

cd ../frontend
pnpm typecheck && pnpm lint && pnpm test:unit && pnpm build && pnpm test:e2e
pnpm exec playwright test --config playwright.real.config.ts   # 需后端 8000 与演示数据

cd ..
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
bash deploy/scripts/rollback_drill.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "D:/code/ai/factoryHR:/repo" -w /repo \
  ghcr.io/gitleaks/gitleaks:latest detect --source=. --config=.gitleaks.toml --no-banner --redact -v
```

真实后端 E2E 需要先启动 Laravel 服务与演示数据：

```bash
cd backend
php artisan migrate:fresh --seed        # 或按 README 的 demo seed 步骤
IAM_LOGIN_RATE_LIMIT_PER_MINUTE=100 php artisan serve --host=127.0.0.1 --port=8000
```

> 登录节流 5 次/分钟是**产品行为**，由 `LoginSecurityTest` 锁定；仅在本地真实 E2E 运行时通过环境变量临时提高，未修改生产默认值。
>
> 演示口令不入库、不写入前端包：`DEMO_SEED_PASSWORD` 由操作者提供，登录页不预填密码；
> 真实后端 E2E 需 `export HRMS_DEMO_PASSWORD=...`，未设置时整套用例以明确原因 skip
> （日志会记录 skip，不会伪装成 pass）。

## 10. 审计签署

| 角色 | 姓名 | 结论 | 日期 |
|---|---|---|---|
| 技术负责人（代码证据） | 自动化审计 | `ACCEPTED_WITH_GATES`（代码证据通过） | 2026-11-23 |
| 安全负责人 | — | 待签（GATE-05） | — |
| 财务负责人 | — | 待签（GATE-02/06） | — |
| 业务发起人 | — | 待签（S-01–S-08、GATE-08） | — |

**声明**：本报告只对可复现的代码证据负责。第 7、8 节列出的项目未完成，不以任何形式视为通过；`ACCEPTED_WITH_GATES` 不等于可以上线。
