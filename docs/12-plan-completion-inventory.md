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

## 9. 凭据事故：APP_KEY 进入 git 历史（已轮换）

**发现过程**：为回答"Docker 为什么没启动"，我把 `docker compose config` 的解析结果写入
`evidence/logs/compose-config.txt`。该文件包含容器环境变量，其中 `APP_KEY` 是本地 `.env` 的**真实密钥**。
它随后被提交。

| 项 | 事实 |
|---|---|
| 泄露内容 | `backend/.env` 的真实 `APP_KEY`（Laravel 加密主密钥） |
| 进入历史的提交 | `f5c5313`（同时 `14364db` 写入工作树版本） |
| 影响范围 | `Crypt::encryptString` 加密的 PII（身份证号、手机号）与 MFA secret |
| 数据规模 | 16 条 person 记录（20 个密文字段） |
| 严重度 | **Sev1**（密钥泄露） |

### 处置

1. **轮换密钥**：生成本地新 `APP_KEY` 并写入 `backend/.env`（`.env` 本身未被跟踪）。
2. **重加密既有数据**：用旧密钥解密 20 个字段 → 用新密钥 `Crypt::encryptString` 写回
   → 验证 `readable_with_new_key=20 unreadable=0`。
3. **清除临时明文**：解密中转文件用后立即删除。
4. **证据文件脱敏**：`compose-config.txt` 改为**只列环境变量名，不打印值**。
5. **回归验证**：`PersonPrivacy`、`FieldPrivacy`、`PersonDedup` 用例全部通过。

### 关于 git 历史：已由项目负责人接受

旧密钥仍存在于提交 `f5c5313` 的文件内容中。**不做历史重写**，原因为：

- 该密钥已**失效**——本地 `.env` 与全部 20 个密文字段都已轮换，历史字符串无法解密任何数据；
- 仓库无 remote，但存在 **13 个 worktree、13 个分支**，重写会破坏其引用；
- 历史重写有引入数据损坏的风险，收益低于"轮换 + 记录"。

**项目负责人决定（2026-11-24）**：本项目**部署在内网，不做任何外网部署**，因此接受该历史
记录保持原样，不执行 `git filter-repo`。

该决定的边界（供后续复核）：

- 接受的是"**已失效的旧密钥出现在内网仓库历史中**"这一风险，不是"密钥可以继续复用"；
- 若部署范围**发生变化**（接入外网、仓库外发、添加远程、移交第三方），该决定需重新评估；
- 任何新环境仍必须生成**独立的新 `APP_KEY`**，不得复用任何历史值——这一点不因内网而改变，
  因为共用密钥会让一个环境的数据可被另一个环境解密。

### 教训（已写入实践）

`docker compose config` 会**解析并展开全部环境变量**，其中包含密钥。任何把 compose 解析结果
写入文件的操作都可能造成密钥落盘。证据文件只能记录**键名**，不能记录**值**。

## 10. Docker Compose 部署栈：已构建、已启动、已验证

**此前状态**：容器栈从未启动，只有 `compose config` 语法校验。
**当前状态**：镜像构建成功，**7 个服务全部 healthy**，API 经 `nginx:8080` 全部验证通过。

| 项 | 结果 |
|---|---|
| `docker compose build` | 成功（`factoryhr/php-app:local`） |
| 运行中的服务 | nginx、php-app、php-worker、php-scheduler、mysql、redis、minio — 全部 healthy |
| `/up`（nginx 健康检查） | 200 |
| `/v1/health` | `status=ok`，`db/cache/storage` 均为 true |
| MySQL | 107 张表，26 条迁移记录 |
| 演示账号 | `demo.admin` / `demo.hr` / `demo.manager` / `demo.employee` 全部登录 200 |
| 受保护端点 | workspace / dashboard / org-tree / notices / leave-balances / employees 全部 200；`payroll/runs` 对 HR 返回 403（职责分离，符合设计） |
| 一键启动 | `DEMO_SEED_PASSWORD=... deploy/scripts/docker_up.sh` 从 `down` 状态完整跑通 |

### 构建过程中发现并修复的 9 个缺陷

这些缺陷**只在容器或 MySQL 下暴露**，SQLite + `artisan serve` 永远不会发现：

| # | 缺陷 | 严重度 | 说明 |
|---|---|---|---|
| 1 | Dockerfile 缺 `sqlite-dev` | Sev2 | `pdo_sqlite` 编译失败，镜像构建中止 |
| 2 | Dockerfile 缺 PHP `redis` 扩展 | Sev2 | worker/scheduler 启动即崩溃（`Class "Redis" not found`） |
| 3 | compose 的 artisan 路径错误 | Sev2 | WORKDIR 是 `/var/www/html`，命令却写 `php artisan`，应为 `php backend/artisan` |
| 4 | nginx `/up` 用 `proxy_pass` 连 FastCGI | Sev2 | php-fpm 说 FastCGI，`proxy_pass` 发 HTTP，健康检查永远 502 |
| 5 | MySQL 索引名超 64 字符 | Sev2 | `workflow_delegations_tenant_id_proxy_user_id_valid_from_valid_to_index` 超限，迁移在 MySQL 上失败（SQLite 无此限制） |
| 6 | `dashboard_snapshots.reason` 列为 VARCHAR(120) | Sev2 | 不可用指标的说明文案超长，仪表盘 500（SQLite 不校验长度） |
| 7 | 演示用户 `auth_version` 未初始化（MySQL 存 0） | **Sev2** | 与绑定的 `auth_version=1` 不匹配，**所有受保护端点 403** |
| 8 | 三个 PHP 服务同时初始化共享 storage 卷 | Sev3 | `docker compose up` 报 `mkdir ... file exists`，栈起不来 |
| 9 | 容器继承 `backend/.env` 的 `IAM_LOGIN_RATE_LIMIT_PER_MINUTE=100` | **Sev2** | 生产容器的登录爆破防护被静默削弱 |

另修复两处依赖/配置问题：

- **`league/flysystem-aws-s3-v3` 从未被声明**：它只是 Laravel 的 suggest，`composer install` 从不安装，
  导致 S3 存储在此仓库**从未真正工作过**（`/v1/health` 的 `storage:false`）。已加入 `require`。
- **nginx 缓存 php-app 的 IP**：`docker compose up` 重建 php-app 后 nginx 仍指向旧地址，产生 502。
  已改为 `resolver 127.0.0.11 valid=10s` + `server ... resolve`。

### 如何完成

```bash
cd deploy/compose
cp .env.example .env          # 填入真实 APP_KEY 与口令
cd ../..

# 一条命令：构建 → 启动 → 迁移 → 灌数据 → 健康检查 → 登录验证
DEMO_SEED_PASSWORD='<至少12位>' bash deploy/scripts/docker_up.sh

# 需要清空数据库重来时
DEMO_SEED_PASSWORD='...' bash deploy/scripts/docker_up.sh --reset
```

验证：

```bash
docker compose -f deploy/compose/docker-compose.yml ps     # 7 services healthy
curl -s http://localhost:8080/v1/health | jq .data.status  # "ok"
```

### 前端也已进入容器并通过浏览器验收

不只是 API：**SPA 已构建进 nginx 镜像，与 API 同源**，用真实 Chromium 验证过。

| 项 | 结果 |
|---|---|
| 构建方式 | Dockerfile 新增 `node:24-alpine` 前端 stage，pnpm 构建后由 `frontend-runtime` 阶段带走 |
| 同源部署 | nginx 同时服务 `/`（SPA）与 `/v1/`（php-fpm），浏览器无需 CORS |
| SPA 路由回退 | `/login`、`/workspace`、`/cases`、`/notices`、`/organization`、`/leave`、`/attendance`、`/dashboard`、`/reports`、`/admin` 全部 200 |
| 静态资源 | `index.html` 引用的 JS/CSS 均 200 |
| 真实登录 | `demo.hr` 在浏览器中登录成功并跳转 `/workspace` |
| 真实数据 | 仪表盘「在职人数 4 人」；员工目录列出 4 人，手机/邮箱/身份证号**全部遮罩** |
| 控制台 | **0 错误 0 警告** |

期间又修复了 4 个前端缺陷：

| # | 缺陷 | 严重度 | 说明 |
|---|---|---|---|
| 10 | 仪表盘标签由后端返回英文（`Headcount`、`View details`） | Sev3 | 中文界面混英文。改为按稳定 `code` 在前端映射中文，`COUNT/MINUTES` 等机器单位也本地化 |
| 11 | **9 处硬编码演示日期 `2026-11-23`** | **Sev2** | 授权绑定从 11-24 起有效，前端却在查 11-23 → 员工目录 **403 后显示"没有匹配的员工"**，看起来像权限 bug。新增 `todayBusinessDate(tz)` 按业务时区取当天 |
| 12 | 员工目录 403 被当成"空列表"展示 | Sev2 | 与 #11 同源；修复日期后恢复 |
| 13 | `el-pagination` 使用已弃用的 `small` 属性 | Sev3 | 控制台每次都有弃用警告 |

### 仍需注意

- 这是**参考/演示部署**：合成数据、占位口令、无 TLS、单机 Compose。生产 HA、
  外部密钥管理、真实 IdP、备份 PITR 仍是 §3 的 GATE。
- MinIO 的固定版本 tag 已不可匿名拉取，compose 改为 `${MINIO_IMAGE:-minio/minio:latest}`；
  真实环境应 pin 到校验过的 digest。
### 如何完成

```bash
# 需要能访问 Docker registry 的网络环境
cd deploy/compose
cp .env.example .env          # 填入真实 APP_KEY 与口令
docker compose build
docker compose up -d
docker compose ps             # 7 个服务应为 healthy
curl -fsS http://localhost:8080/up
curl -fsS http://localhost:8080/v1/health
docker compose exec php-app php artisan migrate --force
```

启动后应把 `compose ps` 与健康检查输出补进 `evidence/logs/docker-compose.txt`，并将本 GATE 改为 PASS。
