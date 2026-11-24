# 计划完成度盘点

> 对照《HRMS_V1.0 实施计划 30 周路线图》逐条核对"创建："清单与"验证："命令后的结果。
> 目的：把"还有什么没做"从口头回答变成可核对的清单。
>
> 盘点基线：`998649f`。复现方式见文末。

## 1. 总览

| 维度 | 状态 |
|---|---|
| 代码可执行门禁 | **23 项全通过**（`PASS=23 FAIL=0 SKIP=0`） |
| 后端测试 | 722 tests / 35,728 assertions，0 failure |
| 前端 | 30 unit + 11 mock E2E + 8 真实后端 E2E |
| ADR | ADR-0001–0015 全部 Accepted |
| 文档 | 计划点名的文档已全部存在（缺 3 个计划从未要求的编号文档，见 §4） |
| 组织/外部 GATE | **9 项未完成**，见 §3 |

## 2. 本轮补齐的项

| 计划出处 | 计划点名 | 处理 |
|---|---|---|
| §4.10 | `docs/09-test-acceptance.md` | 已创建 |
| §4.4 | `docs/06-workflows.md` | 已创建 |
| §4.10 | `deploy/scripts/migrate_organizations.php` | 已创建（包装 `migrate_domain.php`） |
| §4.10 | `deploy/scripts/migrate_people.php` | 已创建 |
| §4.10 | `deploy/scripts/migrate_leave_opening.php` | 已创建 |
| §4.10 | `deploy/scripts/migrate_payroll_ytd.php` | 已创建 |
| §4.10 | `backend/tests/Integration/MigrationReconciliationTest.php` | 已存在（`tests/Feature/Migration/MigrationReconciliationTest.php`） |
| §4.9 | `DashboardConsistencyTest` / `ExportScopeTest` | 已存在 |
| §4.9 | 前端 `self-service` / `approval` / `dashboard` E2E | 以 `tests/e2e/smoke.spec.ts` + `tests/e2e/real/journeys.spec.ts` 覆盖 |

**名称与计划不同但功能已实现**（不是缺口，仅记录以免误判）：

| 计划名 | 实际实现 |
|---|---|
| `CapabilityRegistry` | `App\Modules\IAM\Authorization\CapabilityCatalog` |
| `FieldProjector` | `App\Shared\Authorization\FieldSet` |
| `CorrectionRunService` | `App\Modules\Payroll\Services\CorrectionService` |
| `InputSnapshotService` | `App\Modules\Payroll\Services\SnapshotService` |
| `SalaryScheme` | `App\Modules\Payroll\Models\PayrollScheme` |
| `SalaryItem` | `App\Modules\Payroll\Models\PayrollSchemeItem` |
| `Shift` | `App\Modules\Time\Models\ShiftTemplate` |
| `Enrollment` | `App\Modules\Talent\Models\TrainingEnrollment` |
| `OrgTreeQuery` | `App\Modules\Organization\Services\OrganizationDirectoryService::tree()` |
| `OutboxPublisher` | `App\Shared\Outbox\OutboxRecorder` + `OutboxDispatcher` |
| `BatchImmutabilityTest` | `PayrollRunLifecycleTest` + `PayrollSnapshotTest` |
| `ParallelReconciliationTest` | `PayrollCommandTest`（平行检查 5 个用例） |
| `HolidayCalendarTest` | `tests/Unit/Time/BusinessCalendarTest.php` |
| `PayrollCalculationTest` | `tests/Unit/Modules/Payroll/Domain/*`（22 个文件） |
| `Talent/RecruitmentToHireTest` 等 | `tests/Feature/Lifecycle/{RecruitmentToHire,PerformanceCycle,TalentEnrollment}Test.php` |

## 3. 未完成：组织与外部 GATE（9 项）

这些**不可能**由本仓库代码完成，需要人或外部环境。它们是 `ACCEPTED_WITH_GATES` 中的 GATE。

| 编号 | 事项 | 责任 | 阻断级别 |
|---|---|---|---|
| S-01–S-08 | 组织签认（薪酬口径、法定参数、迁移、权限矩阵、保留期、切换、UAT、Go/No-Go） | 发起人 / HR / 财务 / 法务 / 安全 | 对应 release |
| GATE-01 | 真实 IdP/OIDC 与 SSO 联调 | 安全 / 平台 | R0 后 |
| GATE-02 | 银行代发、税务申报与回执 | 财务 | R3 |
| GATE-03 | 工资条 PDF 与送达 | 产品 / 财务 | R3 |
| GATE-04 | 生产 HA、KMS、MySQL PITR、RTO/RPO 实测 | DevOps | R6 |
| GATE-05 | 第三方渗透测试与整改 | 安全 | R4 |
| GATE-06 | **≥2 个完整周期的真实旧系统工资平行**（当前仅 1 周期 + 合成对照） | 财务 / QA | R3 |
| GATE-07 | 三次生产式迁移彩排（含冻结窗口）；当前仅 SHADOW 单轮 | 数据 / QA | R5 |
| GATE-08 | UAT-01–13 业务签字；自动化已全部映射 | 业务 | R4 |

## 4. 未完成：计划章节存在但未点名的文档

计划 §2.1 的目录结构列出 `00-product.md`、`04-api-contracts.md`、`08-security-compliance.md`，
但正文的"创建："清单从未要求它们。当前状态：

| 文档 | 状态 | 说明 |
|---|---|---|
| `docs/00-product.md` | 缺失 | 产品范围见解决方案文档第 01–02 章 |
| `docs/04-api-contracts.md` | 缺失 | 契约事实源是 `docs/openapi/openapi.yaml`（125 paths / 139 operations），本文件只是索引 |
| `docs/08-security-compliance.md` | 缺失 | 安全实现见 `docs/02-permissions.md` 与 ADR-0004/0009/0013；合规矩阵见 ADR-0015 |
| `docs/plans/` | 缺失 | 计划本身在 `docs/HRMS_V1.0_实施计划_30周路线图.md` |

**判断**：这 4 项是目录结构示意，不是交付要求。是否补写取决于是否要求"目录结构与计划逐字一致"。

## 5. 未完成：代码层的诚实缺口（已知限制）

这些是**功能已实现但有明确边界**的项，逐条记录在 `evidence/acceptance-report.md` §7。

| 缺口 | 影响 | 是否阻断 |
|---|---|---|
| 仪表盘待审批无法区分"分配给我" | Workflow 的投影是租户级、无 assignee 字段 | 否（面板偏保守） |
| 公告受众不展开 ROLE/ORG | 复用 workspace 投影，只会少显示 | 否 |
| 每法人仅一套 SI/HF 规则 | 多地区需扩展 | GATE-02 前 |
| DEMO 法定参数为合成值 | 不构成法律意见 | 上线前需签字 |
| `arch:test` 剩余 4 条警告 | `RetentionService` 动态表名 | 否（已登记复审） |
| 混沌测试、性能压测未执行 | 无对应环境 | 是（计划 §5.2 要求 ≥12 场景、300 并发 P95<500ms） |
| SAST 仅 CI 运行 | 本地无 CodeQL | 否（记为 GATE） |
| 演示口令仍在 git 历史 | 无 remote，未重写历史 | 需轮换，已记录 |

## 6. 未完成：计划 §5.2 中尚无证据的量化门禁

| 门禁 | 计划标准 | 当前 |
|---|---|---|
| 混沌 | ≥12 场景；RTO≤2h、RPO≤15min | **未执行**（仅备份/回滚脚本） |
| 性能 | 300 并发 P95<500ms；错误率<0.5% | **未执行**（无压测环境） |
| 工资平行 | ≥2 个完整周期 | **1 个周期**（GATE-06） |
| 迁移彩排 | 3 次全量 | **1 次 SHADOW**（GATE-07） |
| UAT | 关键角色 100% 签字 | 自动化映射完成，**签字未完成**（GATE-08） |

## 7. 复现本盘点

```bash
cd backend && php artisan test                      # 722 tests
cd .. && bash scripts/verify_release.sh             # 23 gates
ls docs/adr | wc -l                                 # 15
ls docs/runbooks                                    # 3
ls deploy/scripts/migrate_*.php                     # 5（含共用实现）
```

## 8. 结论

**代码层面**：计划中所有可执行的构建项与验证项已完成，23 项门禁全通过，无未关闭 Sev1/Sev2。

**未完成的只有三类**：
1. 需要人签字的组织门禁（S-01–S-08、UAT-08 签字）；
2. 需要外部系统或环境的能力（真实 IdP、银行/税务、HA/PITR、渗透、混沌、压测、真实旧系统导出）；
3. 3 个计划目录提及但正文未要求的索引型文档，以及 1 个 `docs/plans/` 目录。

若要求 30 周生产切换**真正完成**，第 1、2 类必须由组织推进；本仓库不伪造其通过。
