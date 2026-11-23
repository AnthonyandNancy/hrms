## 01 计划原则与承诺边界

> LEAD: 这份计划不按“模块做完一个再做下一个”排列。它先打通一条最小但真实的全链路，再按风险从高到低纵切：时间与组织、假勤账本、工资影子、权限负测、迁移对账。

### 1.0 文档信息

TABLE: 文档基本信息
| 项目 | 内容 |
| 文档性质 | 执行计划（Implementation Plan），以《HRMS V1.0 解决方案：第一性原理重构》为决策基线 |
| 计划周期 | 30 周 P50 情景；38–44 周 P80 管理承诺；W4 用实际 velocity 校准 |
| 团队基线 | 8 FTE：前端 2、后端 3、QA 1、产品/设计 1、DevOps/安全 0.5、HR 业务专家 0.5 |
| 实施范围 | 单公司试点，1–3k 员工；架构按 10k 员工、50–300 并发设计 |
| 版本与日期 | v1.1 / 2026-11-23 |
| 关联文档 | 《HRMS V1.0 解决方案：第一性原理重构》 |
| 计划状态 | 执行基线 v1.1：设计决策已按附录 E 定稿；8 项组织签认按 W2–W8 完成，未签项阻断对应 release |
| 决策基线 | 解决方案附录 E（D-01–D-25、S-01–S-08、T-01–T-06） |

### 1.1 五条交付原则

1. **骨架先行。** 第 4 周必须有一条从 UI 到数据库、鉴权、审计、监控、备份恢复的真实链路，而不是先堆页面。
2. **纵切交付。** 每个 release 交付“页面 + API + 表 + 权限 + 测试 + 文档”的完整切片，不做只有前端的演示。
3. **风险前置。** 工资平行、迁移彩排、权限负测、时间旅行测试不能放到最后一周。
4. **分批可用、统一验收。** 单个 release 可在试点或影子环境使用，但 V1.0 必须通过跨域 E2E、迁移对账、工资平行、安全与 UAT 后才能宣布验收。
5. **门禁不可豁免。** 安全、工资正确性、迁移对账三类门禁不接受“先上线后补测”；未通过就顺延。

### 1.2 承诺边界

TABLE: P50 与 P80 的前提
| 前提 | P50 30 周成立的条件 | 不成立时的影响 |
| 范围 | 组织人事、合同、考勤假勤、薪酬、审批、报表、自助做事务级深度；招聘/绩效/培训做闭环薄切片 | 每增加一个事务级深度模块，增加 3–6 周 |
| 集成 | 标准 OIDC SSO 为主；企业微信/钉钉/飞书经桥接或适配接入；考勤设备、银行、税务、电子签只做适配层，不阻塞主流程 | 每个强制实时集成增加 1–3 周，且受外部排期影响；专有协议接入另加适配工作 |
| 数据 | 历史数据可导出、有唯一键、业务 owner 可投入 | 源数据缺失或需要手工补录，增加 2–6 周 |
| 组织 | 单公司/单法人试点；多法人只保留模型边界 | 多法人并行上线增加 4–8 周 |
| 团队 | 8 FTE 稳定投入，HR 专家每周至少 2 天，关键用户参与 UAT | 关键角色缺位时，计划顺延而不是压缩测试 |
| 决策 | 不可逆决策已定稿（D-01–D-25），W2–W4 完成签认与 ADR，变更走 CCB | 后期改内核模型，返工 6–12 周 |

### 1.3 不进入 30 周关键路径的内容

TABLE: 范围围栏
| 内容 | 处理 | 重新评估时间 |
| 通用 BPMN 设计器 | 不做；只做有限节点与模板版本 | V1.1 后 |
| 微服务拆分 | 不做；模块化单体 | 团队/容量触发时 |
| 多租户 SaaS 计费 | 不做 | 明确商业化后 |
| 原生 App | 不做；响应式自助 | 移动高频场景验证后 |
| 人脸识别算法 | 不自研；采购设备/服务 | 有明确供应商后 |
| 税务申报引擎 | 不自研；适配专业服务 | 有明确地区与供应商后 |
| 银行清算 | 不自研；适配银行/支付服务 | 有明确合作银行后 |
| 完整绩效校准/人才盘点 | 薄切片，不做复杂校准 | V1.1 根据使用数据 |
| 自定义字段承载核心事实 | 禁止；仅外围扩展字段 | 不重新评估 |

## 02 仓库结构与工程基线

### 2.1 单仓库结构

```text
factoryHR/
├─ AGENTS.md
├─ README.md
├─ docs/
│  ├─ 00-product.md
│  ├─ 01-architecture.md
│  ├─ 02-permissions.md
│  ├─ 03-data-model.md
│  ├─ 04-api-contracts.md
│  ├─ 05-ui-guidelines.md
│  ├─ 06-workflows.md
│  ├─ 07-business-rules.md
│  ├─ 08-security-compliance.md
│  ├─ 09-test-acceptance.md
│  ├─ adr/
│  └─ plans/
├─ backend/
│  ├─ app/
│  │  ├─ Modules/
│  │  │  ├─ IAM/
│  │  │  ├─ Organization/
│  │  │  ├─ People/
│  │  │  ├─ Lifecycle/
│  │  │  ├─ Workflow/
│  │  │  ├─ Time/
│  │  │  ├─ Payroll/
│  │  │  ├─ Talent/
│  │  │  ├─ Engagement/
│  │  │  ├─ Reporting/
│  │  │  └─ Platform/
│  │  └─ Shared/
│  │     ├─ Auth/
│  │     ├─ Authorization/
│  │     ├─ Time/
│  │     ├─ Money/
│  │     ├─ Outbox/
│  │     ├─ Audit/
│  │     ├─ Files/
│  │     └─ Testing/
│  ├─ database/migrations/
│  ├─ database/seeders/
│  ├─ routes/api.php
│  └─ tests/{Unit,Feature,Contract,Integration}/
├─ frontend/
│  ├─ src/
│  │  ├─ app/
│  │  ├─ layouts/
│  │  ├─ modules/
│  │  ├─ shared/
│  │  ├─ styles/
│  │  └─ generated/
│  └─ tests/{unit,e2e}/
├─ deploy/
│  ├─ docker/
│  ├─ compose/
│  └─ scripts/
└─ .github/workflows/
```

### 2.2 技术基线

TABLE: 技术选型与锁定
| 层 | 选型 | 锁定要求 |
| 前端 | Vue 3 + TypeScript + Vite + Element Plus | 锁 `pnpm-lock.yaml`；Node LTS；组件版本固定 |
| 后端 | PHP 8.3+ + Laravel 13.x | 锁 `composer.lock`；PHP 8.3/8.4 镜像摘要固定 |
| 数据库 | MySQL 8.4 LTS | 生产独立数据卷或托管；字符集 `utf8mb4`；时区 UTC |
| 缓存/队列 | Redis 7.x | 权限版本、队列、限流；持久化策略明确 |
| 附件 | S3 兼容对象存储（MinIO 开发） | 私有桶、短效签名、病毒扫描 |
| 契约 | OpenAPI 3.1 | 前端类型生成；契约 lint 进 CI |
| 部署 | Docker Compose + Nginx + PHP-FPM + Worker + Scheduler | 健康检查、重启策略、备份、监控 |
| 可观测 | 结构化日志 + 指标 + Trace ID | 请求 ID 贯穿 API、队列、审计 |

### 2.3 工程命令基线

```bash
# 后端
composer install
php artisan migrate --seed
php artisan test
php artisan test --testsuite=Feature
php artisan test --filter=AuthorizationNegativeTest
vendor/bin/phpstan analyse
vendor/bin/pint --test

# 前端
pnpm install
pnpm typecheck
pnpm lint
pnpm test:unit
pnpm test:e2e

# 契约
pnpm openapi:lint
pnpm openapi:generate
php artisan route:list --json > build/routes.json
php artisan capability:audit

# 数据与对账
php artisan projection:rebuild --check
php artisan ledger:reconcile --date=2026-11-30
php artisan payroll:parallel-check --period=2026-11
```

### 2.4 CI 必过门禁

TABLE: CI 门禁
| 阶段 | 命令 | 失败处理 |
| 静态检查 | `vendor/bin/pint --test && vendor/bin/phpstan analyse` | 阻断合并 |
| 后端测试 | `php artisan test` | 阻断合并 |
| 前端检查 | `pnpm typecheck && pnpm lint && pnpm test:unit` | 阻断合并 |
| 契约 | `pnpm openapi:lint && pnpm openapi:generate` | 阻断合并 |
| 能力覆盖 | `php artisan capability:audit` | 阻断合并 |
| 架构边界 | `php artisan arch:test` | 阻断合并 |
| E2E 冒烟 | `pnpm test:e2e --grep @smoke` | 阻断合并 |
| 安全扫描 | 依赖漏洞 + Secret 扫描 + SAST | 高危阻断 |

## 03 30 周路线图

### 3.1 Release 总览

TABLE: Release 与业务结果
| Release | 周次 | 必须产生的可验证业务结果 | 退出标准 |
| R0 骨架 | W1–W4 | 20 人试点可登录、看本人档案、越权返回 403、审计可查；staging 一键部署与备份恢复成功 | UI→API→DB→鉴权→审计→CI/CD→监控→备份全链路打通；契约 lint 0 错误；权限负测基线通过 |
| R1 核心人事 | W3–W9 | HR 完成 1 个真实入职、1 个调岗、1 个合同续签；审批通过才生效 | 有效期/历史/预警可追溯；跨部门/跨公司/直调 API 负测通过；P0/P1 缺陷=0 |
| R2 时间假勤 | W7–W15 | 试点部门跑完 1 个完整考勤周期；请假/加班/出差审批后账本平衡 | 跨夜/重复打卡/节假日/撤销冲正正确；余额未解释差异=0；权限负测全绿 |
| R3 薪酬影子 | W10–W21 | 连续 2 个完整工资周期与旧系统平行；100% 人头、100% 净额可对账 | 未解释差异=0；连续 2 周期通过；批次封存不可改；薪酬负责人签字 |
| R4 人才/自助/报表 | W13–W23 | 招聘→Offer→入职、1 个绩效周期、1 次培训闭环；员工只看本人工资条；仪表盘与下钻一致 | 统计/导出/自助使用同一权限口径；UAT 用例与数据就绪；P0/P1 缺陷=0 |
| R5 加固/迁移/UAT | W19–W26 | 3 次迁移彩排，混沌/性能/安全/UAT 全部完成；W26 go/no-go | 全部门禁通过；Sev1/2 未关闭=0；回滚演练≤60min |
| R6 切换/稳定 | W27–W30 | 试点+分域切换；旧系统只读；连续 2 周稳定运行 | Sev1=0；工资/假勤/人数对账 0 差异；业务签字；进入 90 天 Hypercare |

### 3.2 并行泳道

TABLE: 五条泳道
| 泳道 | 负责 | W1–W6 | W7–W15 | W16–W24 | W25–W30 |
| 平台/IAM/安全 | B1 + DevOps/安全 | 认证、能力目录、Authorizer、审计、CI/CD、备份 | 权限负测、访问复核、JIT、break-glass | 安全加固、渗透、混沌 | 切换支持、Hypercare |
| 核心人事 | B2 + F1 | Person/Employment/Assignment、组织、合同条款 | 入转调离、合同续签、组织移动 | 历史迁移、对账、性能 | 试点切换、问题修复 |
| 时间与薪酬 | B3 + B2 | 时间模型、班次、打卡骨架 | 考勤日、假勤账本、工资规则 | 工资批次、影子、更正 | 工资切换、对账 |
| 人才/自助/报表 | F2 + B3 | 设计系统、自助骨架、报表框架 | 请假/工资条自助 | 招聘/绩效/培训、仪表盘 | UAT、培训、上线 |
| QA/迁移/UAT | QA + HR 专家 | 测试框架、契约测试、数据 profiling | 时间旅行、账本对账、负测 | 迁移彩排、平行、UAT | 切换演练、Hypercare |

### 3.3 关键路径

> KEY: 关键路径不是“页面做完”，而是：组织/员工与有效期 → 审批/幂等 → 考勤/假勤账本 → 工资输入快照与计算 → 两轮工资平行 → 迁移彩排/UAT → 分域切换。

TABLE: 关键路径与缓冲
| 阶段 | 关键路径 | 不能压缩的原因 | 缓冲 |
| W1–W4 | 骨架与权限内核 | 后面所有模块都依赖统一授权与审计 | W4 留 2 天修缺陷 |
| W5–W9 | 人员/任职/组织 + 决策/生效 | 工资、假勤、合同都依赖时间化关系 | W9 留 3 天 |
| W10–W15 | 考勤日 + 假勤账本 | 工资输入依赖账本平衡 | W15 留 3 天 |
| W16–W21 | 工资批次 + 平行运行 | 受自然月约束，加人不能压缩 | W21 留 1 周只修缺陷 |
| W22–W26 | 迁移彩排 + UAT + 加固 | 迁移窗口与业务签字受外部约束 | W25–W26 整周缓冲 |
| W27–W30 | 分域切换 + Hypercare | 工资最后切，旧系统只读 | 每周留 1 天回归 |

### 3.4 周次计划

TABLE: 30 周基准计划
| 周 | 平台/IAM | 核心人事 | 时间/薪酬 | 人才/自助/报表 | QA/迁移 |
| W1 | 仓库、CI、环境、认证骨架 | 领域事件风暴、ER 初稿 | 时间语义 ADR、工资口径调研 | 设计令牌、页面骨架 | 测试策略、数据源清单 |
| W2 | 能力目录、Authorizer 骨架 | Person/Employment/Assignment 迁移 | 班次/打卡模型 | 自助信息架构 | 契约测试框架、数据 profiling |
| W3 | 会话、MFA、审计、权限负测基线 | 组织树/闭包/岗位 | 业务日历/时区 | 登录/导航/权限门 | 负测用例生成器 |
| W4 | R0 门禁、备份恢复、监控 | 员工档案读模型 | 打卡导入骨架 | 本人档案页 | R0 验收、E2E 冒烟 |
| W5 | 角色模板、访问复核 | 合同条款/文件 | 排班骨架 | 组织/员工列表 | 时间旅行测试框架 |
| W6 | JIT/break-glass | 入职 Case/Decision/Effect | 考勤异常 Case | 员工详情时间线 | 合同/入职 E2E |
| W7 | 能力覆盖审计 | 转正/调岗/调薪 Effect | 请假账本模型 | 审批中心 | 假勤账本测试 |
| W8 | 字段档位、导出策略 | 离职检查单 | 请假/加班/出差 API | 自助申请页 | 负测扩展、导出测试 |
| W9 | R1 安全门禁 | R1 业务验收 | 考勤日计算 | 我的合同/档案 | R1 验收、缺陷归零 |
| W10 | 权限性能优化 | 组织移动影响预览 | 跨夜/节假日规则 | 工资条自助骨架 | 考勤测试数据 |
| W11 | 审计查询/告警 | 历史 as-of 查询 | 考勤日投影/对账 | 报表框架 | 账本对账工具 |
| W12 | 撤权/会话吊销 | 合同预警投影 | 假期余额投影 | 假勤报表 | 时间旅行全覆盖 |
| W13 | 访问复核报表 | 跨公司调动 ADR | 工资项目/方案 | 招聘需求/候选人 | 招聘 E2E |
| W14 | 安全基线复核 | 借调/兼岗 UI | 工资输入快照 | 面试/Offer | 工资规则测试 |
| W15 | R2 安全门禁 | 组织/合同回归 | R2 账本验收 | 员工自助假勤 | R2 验收、账本差异=0 |
| W16 | 高危操作双人 | 离职结算输入 | 工资试算/批次 | 绩效模板/周期 | 工资平行准备 |
| W17 | 审计不可变验证 | 合同续签 Case | 工资复核/审批 | 绩效评分/申诉 | 工资差异分析 |
| W18 | 权限模拟器 | 人员历史迁移 | 工资发布/工资条 | 培训计划/报名 | 迁移映射 v1 |
| W19 | 安全扫描修复 | 组织历史迁移 | 工资发放确认/封存 | 仪表盘 KPI | 迁移彩排 1 |
| W20 | 性能压测 | 合同历史迁移 | 更正批次 | 报表下钻 | 迁移彩排 1 复盘 |
| W21 | 混沌测试 | 人事模块冻结 | R3 工资平行验收 | 自助工资条/公告 | R3 验收、差异=0 |
| W22 | 渗透测试 | 缺陷修复 | 工资回归 | 招聘/绩效/培训 UAT | 迁移彩排 2 |
| W23 | 安全整改 | 数据对账 | 工资对账 | R4 业务验收 | R4 验收、UAT 启动 |
| W24 | 发布候选 | 功能冻结 | 功能冻结 | 功能冻结 | 全量回归、UAT |
| W25 | 门禁复核 | 迁移演练 | 工资最终平行 | UAT 缺陷修复 | 迁移彩排 3 |
| W26 | Go/No-Go | Go/No-Go | Go/No-Go | Go/No-Go | 切换演练、回滚演练 |
| W27 | 切换支持 | 试点切换 | 考勤/假勤切换 | 自助上线 | 切换值守 |
| W28 | 稳定化 | 问题修复 | 工资影子继续 | 培训/推广 | 对账 |
| W29 | 性能/安全观察 | 旧系统只读 | 工资切换 | 使用反馈 | Hypercare |
| W30 | 复盘 | 复盘 | 复盘 | 复盘 | 退出 Hypercare 评审 |

## 04 工作包与任务分解

> LEAD: 下面每个工作包都按“目标—文件—接口—任务—验证—退出标准”组织。任务可以在执行时拆成 2–5 分钟的步骤，但交付边界不变。

### 4.1 E01 工程骨架与可观测

**目标：** 第 4 周前打通一条真实的全链路，任何后续模块都复用同一套认证、授权、审计、错误、日志、部署与备份。

**文件：**
- 创建：`backend/composer.json`、`backend/.env.example`、`backend/routes/api.php`
- 创建：`backend/app/Shared/ApiResponse.php`、`backend/app/Shared/RequestId.php`、`backend/app/Shared/Exceptions/Handler.php`
- 创建：`deploy/compose/docker-compose.yml`、`deploy/docker/nginx.conf`、`deploy/docker/php.Dockerfile`
- 创建：`.github/workflows/ci.yml`、`.github/workflows/security.yml`
- 创建：`docs/01-architecture.md`、`docs/adr/0001-modular-monolith.md`

**接口：**
- `GET /v1/health` → `{status, version, db, redis, storage}`
- 统一响应：`{code, message, data, request_id}`
- 统一异常：401/403/404/409/422/429

**任务：**
- [ ] 建立 Laravel 13 + Vue 3 单仓库，锁定 PHP 8.3、MySQL 8.4、Redis 7、Node LTS。
- [ ] 实现 `RequestId` 中间件，把 `request_id` 注入日志、响应、审计与队列。
- [ ] 实现统一异常处理与错误码表，禁止业务代码直接返回 200 表示失败。
- [ ] 建立 Docker Compose：nginx、php-app、php-worker、php-scheduler、mysql、redis、minio。
- [ ] 建立 CI：静态检查、后端测试、前端检查、契约 lint、安全扫描。
- [ ] 建立 staging 一键部署与备份恢复脚本。
- [ ] 建立健康检查、结构化日志、基础指标与告警。

**验证：**
```bash
docker compose -f deploy/compose/docker-compose.yml up -d
curl -s http://localhost:8080/v1/health | jq
php artisan test --filter=HealthTest
bash deploy/scripts/backup_restore_smoke.sh
```

**退出标准：**
- staging 可从零部署；健康检查全绿；备份恢复演练成功。
- 任意请求都有 `request_id`，日志可追踪。
- CI 在空仓库上全绿。

### 4.2 E02 身份、能力目录与授权内核

**目标：** 建立“默认拒绝、能力代码化、锚点绑定、字段档位、撤权即时”的授权内核，并用负向测试锁住。

**文件：**
- 创建：`backend/app/Modules/IAM/Models/User.php`、`Session.php`、`Capability.php`、`AuthorityBinding.php`
- 创建：`backend/app/Shared/Authorization/Authorizer.php`、`AnchorResolver.php`、`FieldProjector.php`、`CapabilityRegistry.php`
- 创建：`backend/database/migrations/*_create_iam_tables.php`
- 创建：`backend/tests/Feature/Authorization/DenyByDefaultTest.php`、`AnchorScopeTest.php`、`FieldProjectionTest.php`
- 创建：`docs/02-permissions.md`

**接口：**
```php
interface Authorizer
{
    public function constrain(
        Builder $query,
        Principal $principal,
        string $capability,
        BusinessDate $on
    ): Builder;

    public function fields(
        Principal $principal,
        string $capability,
        ResourceRef $row
    ): FieldSet;
}
```

**任务：**
- [ ] 建立 `capabilities` 目录与 `authority_bindings` 表；能力码由代码注册，UI 只读。
- [ ] 实现标准 OIDC SSO 登录、企业微信/钉钉/飞书桥接适配、本地账号兜底、会话、MFA、密码策略、限流、登录审计（D-09–D-11）。
- [ ] 会话 Cookie 使用 HttpOnly、Secure、SameSite=Lax 并加 CSRF 防护；标准用户 12h/30min，特权用户 8h/15min；每个请求校验 `auth_version`。
- [ ] 实现 MFA：TOTP 与 WebAuthn；特权与薪酬角色强制；短信/邮件验证码不作为第二因子。
- [ ] 实现高风险 step-up 与 break-glass：双人、30 分钟、理由、全量告警、自动过期；不保留永久超级管理员。
- [ ] 实现 `Authorizer::constrain()`，只允许 7 类 Anchor：SELF、MANAGED、ORG_UNIT、ORG_SUBTREE、CASE、PAYROLL_RUN、LEGAL_ENTITY。
- [ ] 实现 `FieldProjector`，字段档位 P0–P3；HIDDEN 字段不得进入 WHERE/ORDER BY/序列化/导出。
- [ ] 实现 `auth_version` 与撤权：角色/绑定变更后递增版本，下一次请求拒绝旧版本。
- [ ] 建立 10 个角色模板，禁止人名/部门名角色；例外角色必须有 owner、目的、到期日。
- [ ] 实现 `GET /v1/me`、`GET /v1/me/capabilities`、`POST /v1/auth/logout`。
- [ ] 建立权限负测生成器：每个受保护路由至少 4 类拒绝，高危 8 类。

**验证：**
```bash
php artisan test --filter=DenyByDefaultTest
php artisan test --filter=AnchorScopeTest
php artisan test --filter=FieldProjectionTest
php artisan capability:audit
```

**退出标准：**
- 未登录、无能力、越锚点、越字段四类请求全部拒绝。
- 撤权后下一次请求 100% 被拒绝，不依赖前端刷新；改密、离职、转岗、设备丢失后旧会话同样失效。
- 能力覆盖率 100%，无路由绕过 Authorizer。
- SSO 登录与本地兜底都可完成；MFA 对特权角色强制；break-glass 有双人、时限与审计记录。

### 4.3 E03 组织、人员与任职内核

**目标：** 建立 Person–Employment–Assignment、组织闭包、合同条款与 as-of 查询，让历史、未来生效、兼岗、借调、跨公司可表达。

**文件：**
- 创建：`backend/app/Modules/Organization/Models/OrgUnit.php`、`OrgUnitClosure.php`、`Position.php`
- 创建：`backend/app/Modules/People/Models/Person.php`、`Employment.php`、`Assignment.php`、`EmploymentTerms.php`
- 创建：`backend/app/Modules/People/Queries/AsOfEmployeeQuery.php`、`OrgTreeQuery.php`
- 创建：`backend/database/migrations/*_create_people_tables.php`
- 创建：`backend/tests/Feature/People/AssignmentTimelineTest.php`、`OrgClosureTest.php`、`AsOfQueryTest.php`
- 创建：`docs/03-data-model.md`

**接口：**
- `GET /v1/employees?as_of=YYYY-MM-DD&org_unit_id=...`
- `GET /v1/employees/{employment_id}?as_of=YYYY-MM-DD`
- `POST /v1/employees`、`POST /v1/employments/{id}/assignments`
- `GET /v1/org-units/tree?as_of=YYYY-MM-DD`

**任务：**
- [ ] 建立 `persons`、`employments`、`assignments`、`employment_terms`、`documents` 迁移与约束。
- [ ] 建立 `org_units`、`org_unit_closure`、`positions`，组织移动追加新闭包，不覆盖旧路径。
- [ ] 实现 `tenant_id` 与 `legal_entity_id` 边界：工号在租户加法人内唯一；跨法人调动结束旧 Employment、建立新 Employment；共享服务组织用显式映射（D-02）。
- [ ] 实现 as-of 查询助手，统一 `valid_from <= on < valid_to` 与 `known_at` 过滤；区间半开，开区间用 `9999-12-31`。
- [ ] 实现 `PRIMARY` Assignment 不重叠校验；并发写入用事务锁。
- [ ] 实现跨法人校验：Assignment 的组织必须属于 Employment 的法人，跨法人走新 Employment 或借调关系。
- [ ] 实现组织移动影响预览：影响员工数、审批人、报表、工资成本中心。
- [ ] 建立员工档案读模型与时间线 UI。
- [ ] 建立合同条款版本、文件哈希、到期预警投影。

**验证：**
```bash
php artisan test --filter=AssignmentTimelineTest
php artisan test --filter=OrgClosureTest
php artisan test --filter=AsOfQueryTest
php artisan test --filter=CrossLegalEntityTest
```

**退出标准：**
- 兼岗、借调、跨公司调动、返聘均有测试用例。
- 组织移动后历史 as-of 查询结果不变。
- 员工列表、详情、组织树、下钻使用同一 as-of 口径。

### 4.4 E04 决策与生效

**目标：** 用 Case–Decision–Effect 替代“审批状态写业务表”，让审批通过与业务生效各自可解释、可重试、可补偿。

**文件：**
- 创建：`backend/app/Modules/Workflow/Models/CaseModel.php`、`Decision.php`、`Effect.php`、`WorkflowInstance.php`、`WorkflowTask.php`
- 创建：`backend/app/Modules/Workflow/Effects/EffectHandler.php`、`EffectRegistry.php`
- 创建：`backend/app/Shared/Outbox/OutboxPublisher.php`、`IdempotencyService.php`
- 创建：`backend/tests/Feature/Workflow/DecisionImmutabilityTest.php`、`EffectIdempotencyTest.php`、`ReassignTest.php`
- 创建：`docs/06-workflows.md`

**接口：**
```php
interface EffectHandler
{
    public function apply(DecisionId $decision, EffectId $effect): EffectResult;
}
```

- `POST /v1/cases`
- `POST /v1/cases/{id}/decisions`
- `GET /v1/cases/{id}/timeline`
- `POST /v1/effects/{id}/retry`

**任务：**
- [ ] 建立 Case/Decision/Effect/Workflow 表与状态机；Decision 不可变。
- [ ] 实现模板版本与节点快照；修改模板不影响历史实例。
- [ ] 实现审批人解析：指定用户、直属上级、部门负责人、角色用户、会签/或签。
- [ ] 实现提交、撤回、同意、驳回、转交、抄送；审批人必须是当前节点候选人。
- [ ] 实现并发与幂等：`UNIQUE(case_id, node_id, actor_id, decision_seq)`。
- [ ] 实现本地 Effect 同事务、未来 Effect 调度、外部副作用 Outbox。
- [ ] 实现经理转岗/离职/长休的待办重指派与代理审批限额。
- [ ] 实现统一 Case 时间线 API，分离“申请状态/生效状态”。

**验证：**
```bash
php artisan test --filter=DecisionImmutabilityTest
php artisan test --filter=EffectIdempotencyTest
php artisan test --filter=ConcurrentApprovalTest
php artisan test --filter=ReassignTest
```

**退出标准：**
- 并发双击通过只产生一次有效 Decision 与 Effect。
- 审批通过但 Effect 失败时，UI 明确显示“已通过、未生效”，可重试。
- 历史实例不受模板修改影响。

### 4.5 E05 入转调离与合同

**目标：** 用 Decision/Effect 驱动入职、转正、调岗、调薪、离职、合同续签，所有变化有生效日与旧值。

**文件：**
- 创建：`backend/app/Modules/Lifecycle/Services/OnboardingService.php`、`TransferService.php`、`OffboardingService.php`、`ContractService.php`
- 创建：`backend/tests/Feature/Lifecycle/OnboardingEffectTest.php`、`TransferFutureEffectTest.php`、`OffboardingChecklistTest.php`、`ContractRenewalTest.php`
- 创建：`docs/07-business-rules.md`

**任务：**
- [ ] 入职：候选人/手工登记 → 去重 → 审批 → 创建 Employment/Assignment → 账号策略 → 任务清单。
- [ ] 转正：试用期到期、审批、Employment 状态 Effect、提醒。
- [ ] 调岗/调部门：新 Assignment 版本、未来生效、影响预览、旧值保留。
- [ ] 调薪：新 EmploymentTerms 版本、生效日、工资方案关联、不回写历史批次。
- [ ] 离职：检查单（交接、账号、合同、假勤、工资、设备）、Effect、凭证吊销。
- [ ] 合同：签订、续签、变更、到期预警、文件版本、签署证据。
- [ ] 跨公司调动：旧 Employment 结束、新 Employment 开始、工龄/年假/社保连续性规则。
- [ ] 返聘/再入职：Person 复用、新 Employment、工龄规则。

**验证：**
```bash
php artisan test --filter=OnboardingEffectTest
php artisan test --filter=TransferFutureEffectTest
php artisan test --filter=OffboardingChecklistTest
php artisan test --filter=ContractRenewalTest
```

**退出标准：**
- 真实完成 1 个入职、1 个调岗、1 个合同续签，审批通过后才生效。
- 未来生效在到期前不改变当前视图，到期后自动生效且不重复。
- 离职后账号、会话、代理审批、导出任务全部失效。

### 4.6 E06 时间、考勤与假勤账本

**目标：** 建立业务日历、班次、打卡、考勤日投影、请假/加班/出差账本，保证跨夜、节假日、撤销、冲正正确。

**文件：**
- 创建：`backend/app/Modules/Time/Models/Shift.php`、`ShiftAssignment.php`、`PunchRecord.php`、`AttendanceDay.php`、`LeaveLedgerEntry.php`
- 创建：`backend/app/Modules/Time/Services/AttendanceCalculator.php`、`LeaveLedgerService.php`、`BusinessCalendar.php`
- 创建：`backend/tests/Feature/Time/OvernightShiftTest.php`、`LeaveLedgerBalanceTest.php`、`HolidayCalendarTest.php`、`ReversalTest.php`

**任务：**
- [ ] 建立法人时区（IANA）、节假日、调休、班次、排班模型；规则版本化；业务日不得用 `DATE(punched_at)` 计算（D-03）。
- [ ] 实现法定年休假基线：累计工作满 1 年 5 天、满 10 年 10 天、满 20 年 15 天；法定假类型不得低于法定下限（D-17）。
- [ ] 打卡原始记录不可变；标准化 UTC 时间；考勤日按业务日历计算。
- [ ] 实现迟到、早退、缺卡、重复打卡、跨夜班次、加班计算。
- [ ] 实现补卡/修正 Case，生效后重算考勤日，不修改原始打卡。
- [ ] 实现请假账本：GRANT/RESERVE/CONSUME/RELEASE/EXPIRE/ADJUST/REVERSE。
- [ ] 实现请假、加班、出差冲突检测；审批后占用/结算；撤销后释放/冲正。
- [ ] 实现余额投影与“为什么是这个数”下钻。
- [ ] 实现考勤日/账本对账命令，未解释差异=0。

**验证：**
```bash
php artisan test --filter=OvernightShiftTest
php artisan test --filter=LeaveLedgerBalanceTest
php artisan test --filter=HolidayCalendarTest
php artisan test --filter=ReversalTest
php artisan ledger:reconcile --date=2026-11-30
```

**退出标准：**
- 试点部门跑完 1 个完整考勤周期。
- 跨夜、重复打卡、节假日、撤销冲正全部通过。
- 账本期初+获得+调整−消耗−过期±冲正=期末，差异=0。

### 4.7 E07 薪酬与平行运行

**目标：** 建立工资项目、方案、批次、输入快照、计算、复核、发布、封存、更正，连续 2 个周期与旧系统平行。

**文件：**
- 创建：`backend/app/Modules/Payroll/Models/SalaryItem.php`、`SalaryScheme.php`、`PayrollRun.php`、`Payslip.php`、`PayslipLine.php`
- 创建：`backend/app/Modules/Payroll/Services/PayrollCalculator.php`、`InputSnapshotService.php`、`CorrectionRunService.php`
- 创建：`backend/tests/Feature/Payroll/PayrollCalculationTest.php`、`BatchImmutabilityTest.php`、`CorrectionRunTest.php`、`ParallelReconciliationTest.php`
- 创建：`docs/07-business-rules.md` 工资章节

**任务：**
- [ ] 工资项目、方案、规则版本；金额用整数最小单位（分），禁止 PHP float；费率与基数用定点且至少 6 位小数（D-15）。
- [ ] 批次生命周期：DRAFT → INPUT_LOCKED → CALCULATED → REVIEWED → APPROVED → PUBLISHED → PAID → CLOSED。
- [ ] 输入快照：考勤日、假勤账本、合同条款、工资方案、手工调整；快照含版本与哈希。
- [ ] 计算：应发、扣减、实发、累计预扣、社保/公积金基数与费率；每行有来源；默认每个法定步骤末 half-up 到分，地区化舍入模式与精度版本化。
- [ ] 法定参数按法人/参保地版本化，财务与法务签字后生效；税率、费率、上下限不得硬编码在业务代码。
- [ ] 税务申报与银行代发走外部适配或 CSV 兜底；银行回执、申报回执与工资批次逐批对账。
- [ ] 复核/审批/发布/发放确认；制单、复核、发布职责分离。
- [ ] 封存后不可改；更正批次引用原批次，生成补发/补扣。
- [ ] 工资条自助仅本人可见；字段档位与导出策略。
- [ ] 平行运行：两个周期、100% 人头、100% 净额对账；差异分析报告。
- [ ] 保留期与删除：工资支付记录≥2 年、会计凭证/账簿 30 年、月度/季度/半年报告 10 年、年度报告永久、劳动合同至解除后≥2 年；法务保全优先（D-18）。

**验证：**
```bash
php artisan test --filter=PayrollCalculationTest
php artisan test --filter=BatchImmutabilityTest
php artisan test --filter=CorrectionRunTest
php artisan payroll:parallel-check --period=2026-11
php artisan payroll:parallel-check --period=2026-12
```

**退出标准：**
- 连续 2 个工资周期未解释差异=0；例外经薪酬负责人签字。
- 封存批次不可改；更正批次可追溯；原批次 + 更正批次 = 应付总额。
- 工资条、报表、导出使用同一字段与范围授权。
- 舍入、累计预扣、社保公积金基数用例全部通过；法定参数有版本与签字记录；银行/申报回执可对账。

### 4.8 E08 招聘、绩效、培训薄切片

**目标：** 完成招聘→Offer→入职、绩效周期→评分→申诉、培训计划→报名→结果三个闭环，每域只做一条主流程。

**文件：**
- 创建：`backend/app/Modules/Talent/Models/Requisition.php`、`Candidate.php`、`Interview.php`、`Offer.php`
- 创建：`backend/app/Modules/Talent/Models/PerformanceCycle.php`、`PerformanceReview.php`、`TrainingProgram.php`、`Enrollment.php`
- 创建：`backend/tests/Feature/Talent/RecruitmentToHireTest.php`、`PerformanceCycleTest.php`、`TrainingEnrollmentTest.php`

**任务：**
- [ ] 招聘需求审批、候选人、面试、Offer、转入职映射与去重。
- [ ] 绩效模板、周期、目标、评分、校准薄切片、申诉。
- [ ] 培训计划、场次、报名、签到、结果、统计。
- [ ] 三域均使用统一 Case/Decision/Effect、权限与审计。
- [ ] 明确不在 V1.0 的复杂校准、人才盘点、学习路径。

**验证：**
```bash
php artisan test --filter=RecruitmentToHireTest
php artisan test --filter=PerformanceCycleTest
php artisan test --filter=TrainingEnrollmentTest
```

**退出标准：**
- 招聘→Offer→入职生成唯一员工档案与任职。
- 绩效评分留痕、申诉可追踪。
- 培训报名与结果可按部门/人员/周期查询。

### 4.9 E09 员工自助、审批中心、公告与报表

**目标：** 让员工和经理在授权范围内完成自助与审批，让仪表盘、报表、导出同源同权限。

**文件：**
- 创建：`frontend/src/modules/self-service/`、`frontend/src/modules/workflow/`、`frontend/src/modules/dashboard/`
- 创建：`backend/app/Modules/Engagement/`、`backend/app/Modules/Reporting/`
- 创建：`frontend/tests/e2e/self-service.spec.ts`、`approval.spec.ts`、`dashboard.spec.ts`
- 创建：`backend/tests/Feature/Reporting/DashboardConsistencyTest.php`、`ExportScopeTest.php`

**任务：**
- [ ] 我的档案、我的合同、我的考勤、我的假期、我的申请、我的工资条、我的绩效、我的培训、公告。
- [ ] 审批中心：待办、已办、我发起、抄送、时间线、批量阈值、移动端。
- [ ] 公告：发布、撤回、定向可见、已读确认。
- [ ] 仪表盘 KPI：员工总数、在职、试用期、部门/岗位、待审批、合同到期、招聘漏斗。
- [ ] 报表中心：筛选、图表、下钻、导出、定时任务。
- [ ] 所有统计与下钻使用同一投影与授权谓词。
- [ ] 导出：字段白名单、行数上限、水印、短效链接、审计。

**验证：**
```bash
php artisan test --filter=DashboardConsistencyTest
php artisan test --filter=ExportScopeTest
pnpm test:e2e --grep "@self-service|@approval|@dashboard"
```

**退出标准：**
- 仪表盘数字与下钻列表一致；导出与页面同权限。
- 员工不能通过修改 ID 查看他人数据。
- 审批中心可看到申请状态与生效状态两条线。

### 4.10 E10 迁移、切换与 Hypercare

**目标：** 三次迁移彩排、分域切换、工资影子、90 天 Hypercare，确保旧系统可回退、已发工资可更正。

**文件：**
- 创建：`deploy/scripts/migrate_organizations.php`、`migrate_people.php`、`migrate_leave_opening.php`、`migrate_payroll_ytd.php`
- 创建：`backend/tests/Integration/MigrationReconciliationTest.php`
- 创建：`docs/09-test-acceptance.md`、`docs/runbooks/cutover.md`、`docs/runbooks/rollback.md`
- 创建：`docs/runbooks/hypercare.md`

**任务：**
- [ ] 数据 profiling：唯一键、重复、孤儿、部门映射、合同、工资历史。
- [ ] 迁移顺序：组织 → 人员 → 任职 → 合同 → 假勤期初账本 → 工资 YTD。
- [ ] 假期余额以期初账本分录迁移；工资 YTD 以期初余额迁移。
- [ ] 三次彩排：全量、生产式冻结窗口、最终走查；每次对账人数、组织、合同、工资总额。
- [ ] 分域切换：核心人事 → 考勤假勤 → 工资 → 人才/自助/报表；每波 go/no-go。
- [ ] 回滚演练：数据库快照/PITR、功能开关、路由切换；回滚≤60min。
- [ ] Hypercare：Sev1/2、工资/假勤/人数对账、工单、使用率、变更失败率。

**验证：**
```bash
php artisan test --filter=MigrationReconciliationTest
bash deploy/scripts/migration_rehearsal.sh --round=1
bash deploy/scripts/migration_rehearsal.sh --round=2
bash deploy/scripts/migration_rehearsal.sh --round=3
bash deploy/scripts/rollback_drill.sh
```

**退出标准：**
- 三次彩排源记录 100% 可追溯；人数/在职/部门/合同/工资总额逐项对平。
- 第三轮冻结窗口≤4h、增量≤30min、回滚≤60min。
- 分域切换每波有 go/no-go 记录与回滚点。
- 90 天 Hypercare 指标达标后退出。

## 05 测试与质量门禁

### 5.1 测试金字塔

TABLE: 测试类型与责任
| 类型 | 覆盖 | 工具 | 责任 |
| 单元测试 | 领域规则、金额、时间、账本 | PHPUnit / Vitest | 开发 |
| 契约测试 | OpenAPI、前端类型、消费者 | Schemathesis / 自研 | 后端 + 前端 |
| 功能测试 | API 成功/失败/边界 | PHPUnit Feature | 后端 |
| 权限负测 | 未认证/无能力/越锚点/越字段/跨法人/旧 token | 自研生成器 | 后端 + QA |
| 时间旅行 | T-1/T/T+1、跨夜、跨年、时区 | Fake Clock | 后端 + QA |
| 账本对账 | 假勤、工资、编制 | Artisan 命令 | QA + 财务 |
| E2E | 关键用户旅程 | Playwright | QA + 前端 |
| 性能 | 300 并发、10k 员工、工资批次 | k6 / JMeter | DevOps + 后端 |
| 混沌 | DB/Redis/队列/存储/网络故障 | Toxiproxy / 脚本 | DevOps |
| 安全 | IDOR、提权、导出、文件下载 | OWASP ZAP / 手工 | 安全 + QA |

### 5.2 量化门禁

TABLE: Release 门禁
| 门禁 | 量化标准 | 阻断级别 |
| 契约 | OpenAPI lint 0 错误；100% 受保护端点入契约；前端类型编译通过 | 阻断 |
| 权限负测 | 每端点≥4 类拒绝；高危≥8 类；P0/P1 越权=0；撤权后下一请求 100% 403 | 阻断 |
| 时间旅行 | 100% 有效期对象有 fake clock；T-1/T/T+1、跨夜、跨年、时区边界通过 | 阻断 |
| 身份与会话 | SSO 与本地兜底均可登录；MFA 对特权角色强制；撤权后旧会话下一请求 403；会话时长符合 D-10 | 阻断 |
| 账本平衡 | 每日对账；未解释差异=0；重复审批/重试 10 次不重复扣减 | 阻断 |
| 工资平行 | ≥2 个完整周期；100% 人头；100% 净额±0.01 元或签字例外；未解释差异=0；累计预扣、地区化舍入、基数用例 100% 通过 | 阻断 |
| 迁移彩排 | 3 次全量；源记录 100% 可追溯；人数/组织/合同/工资总额对平；孤儿=0 | 阻断 |
| 混沌 | ≥12 场景；P0 流程安全降级；无重复工资/假勤/审批；RTO≤2h、RPO≤15min | 阻断 |
| UAT | P0 场景 100%；P1≥95%；Sev1/2 未关闭=0；关键角色 100% 签字 | 阻断 |
| 性能 | 300 并发 P95<500ms；错误率<0.5%；工资批次在窗口内完成 | 阻断 |
| 安全 | 无高危未修复；无默认口令；无公开敏感附件；日志无敏感明文；break-glass 有双人、时限与告警 | 阻断 |
| 合规 | 字段五元组齐全；保留矩阵可执行；法务保全命中不删除；无未经审批的跨境数据流 | 阻断 |

### 5.3 测试数据策略

- 开发/测试使用合成数据，不复制生产个人数据。
- 生产式迁移彩排使用脱敏数据；敏感字段替换但保留格式与边界。
- 每个测试用例自带数据工厂，禁止依赖共享全局数据。
- 时间、随机、ID 生成必须可注入，测试可重放。
- 工资平行使用旧系统导出 + 新系统计算 + 差异报告，不手工改数对齐。

### 5.4 缺陷分级与修复 SLA

TABLE: 缺陷等级
| 等级 | 定义 | 修复 SLA | 发布影响 |
| Sev1 | 数据泄露、工资错发、系统不可用 | 立即响应，4h 内缓解 | 阻断发布，必要时回滚 |
| Sev2 | 核心流程不可用、账本/权限错误 | 1 个工作日内 | 阻断发布 |
| Sev3 | 非核心功能错误、体验问题 | 当前 sprint | 可带计划发布 |
| Sev4 | 文案、样式、建议 | 排期 | 不阻断 |

## 06 技术落地细节

### 6.1 时间与金额

- 所有时间戳存 UTC；业务日期存 `DATE`；法人时区用 IANA 标识（如 `Asia/Shanghai`），不使用固定偏移（D-03）。
- 金额用整数最小单位（分）；费率、基数与中间结果用十进制定点并至少保留 6 位小数；禁止 PHP float（D-15）。
- 默认每个法定计算步骤末四舍五入到分（half-up）；地区/税种口径不同时，舍入模式与精度作为版本化参数；更正允许负数，但不出现负零。
- 日期区间统一半开 `[valid_from, valid_to)`；开区间用 `9999-12-31`。
- 所有有效期表必须有 `recorded_at`、`superseded_at`、`source_type`、`source_id`。
- 禁止业务代码手写 `whereDate` 处理 as-of；统一使用 `AsOfQuery` 助手。
- 工资、考勤、请假、合同使用同一法人日历与同一业务日口径；跨夜归属按班次规则，不按自然日截断。

### 6.2 查询与索引

TABLE: 关键索引
| 表 | 索引 | 用途 |
| employments | `(tenant_id, legal_entity_id, employee_no)` 唯一 | 工号唯一 |
| assignments | `(employment_id, valid_from, valid_to)` | 时间线 |
| assignments | `(org_unit_id, valid_from, valid_to)` | 组织人数 |
| org_unit_closure | `(ancestor_id, descendant_id, valid_from)` | 子树查询 |
| authority_bindings | `(principal_id, capability_code, anchor_type, anchor_id, valid_from)` | 授权解析 |
| leave_ledger_entries | `(employment_id, leave_type, business_date)` | 余额投影 |
| attendance_days | `(employment_id, business_date)` 唯一 | 考勤日 |
| payslips | `(payroll_run_id, employment_id)` 唯一 | 工资条 |
| audit_events | `(tenant_id, occurred_at)`、`(aggregate_type, aggregate_id)` | 审计查询 |

### 6.3 队列与 Outbox

- 本地确定性效果不走队列；与 Decision 同事务。
- Outbox 只发通知、搜索、投影、外部集成；事件只带 ID 与版本。
- 消费者使用 inbox 表或唯一键去重；失败重试有上限与死信队列。
- 队列积压、死信、重复事件、投影延迟必须有指标与告警。
- 外部副作用使用 Process Manager，定义超时、重试、补偿与人工接管。

### 6.4 文件与附件

- 私有桶；上传后病毒扫描、MIME/后缀校验、大小限制。
- 下载使用短效签名 URL；每次下载鉴权并审计。
- 合同、证件、工资附件按字段档位与目的授权。
- 文件元数据不泄露敏感信息；错误信息不返回存储路径。
- 离职/删除请求按保留策略处理，不直接物理删除法定留存文件。

### 6.5 审计与保留期

TABLE: 必须审计的事件
| 事件 | 内容 | 保留 |
| 登录/登出/失败 | 用户、IP、UA、结果、request_id | 安全日志 3 年 |
| 授权变更 | 主体、能力、锚点、字段、前后差异、审批单 | 3 年，离职后仍保留 |
| 敏感字段访问 | 谁、何时、哪个对象、哪个字段、目的 | 3 年 |
| 导出/下载 | 查询条件、字段、行数、接收人、链接有效期 | 3 年 |
| 决策/生效 | Case、Decision、Effect、状态、失败原因 | 与业务记录同期 |
| 工资批次 | 建立、计算、复核、审批、发布、发放、封存、更正 | 支付记录≥2 年；会计凭证/账簿 30 年 |
| 配置变更 | 字典、参数、模板、组织、能力元数据 | 3 年，能力语义随代码版本永久可查 |

TABLE: 保留期基线
| 记录类型 | 最低保留期 | 依据 | 备注 |
| 工资支付记录 | 2 年 | 工资支付暂行规定第六条 | 作为会计凭证/账簿时按 30 年 |
| 会计凭证、会计账簿 | 30 年 | 会计档案管理办法附表 | 从会计年度终了后第一天算起 |
| 月度、季度、半年度财务报告 | 10 年 | 会计档案管理办法附表 | 年报永久 |
| 年度财务报告 | 永久 | 会计档案管理办法附表 | — |
| 劳动合同 | 至解除/终止后至少 2 年 | 劳动合同法第五十条 | 建议按更长的诉讼时效与内控要求评估 |
| 个人信息 | 实现目的所需最短时间 | PIPL 第十九条 | 法务保全优先于到期删除 |
| 安全与审计日志 | 3 年 | 内控与审计实践 | 高风险事件可按事件延长 |

> WARNING: 保留期是基线不是上限。法务保全、诉讼、监管调查一旦命中，删除任务必须先检查保全标记；到期删除只做 dry-run 与审批后执行，不能由定时任务直接物理删除。

### 6.6 性能与容量

- 目标：1–3k 员工试点，10k 员工设计；50–300 并发。
- 常规分页 P95 < 500ms（服务端）；工资批次在窗口内完成。
- 列表查询必须走索引；禁止 N+1；授权谓词编译为 `EXISTS`/`IN`。
- 报表与导出异步；大导出分片、限流、短效下载。
- 压测场景：员工列表、组织树、审批待办、工资批次、仪表盘、导出。

## 07 权限落地检查清单

> LEAD: 权限不是“做完了”就结束，而是每个 release 都要证明：没有路由绕过授权、没有字段从导出漏出、撤权在下一次请求生效。这张清单是发布前的硬检查。

TABLE: 权限实施检查
| 检查项 | 通过标准 | 证据 |
| 能力目录 | 所有受保护路由有 capability；无 UI 创建能力码 | `capability:audit` 报告 |
| 默认拒绝 | 无能力返回 403；未知路由不泄露 | 负测报告 |
| 锚点解析 | 7 类 Anchor 有单元测试；无 CUSTOM SQL | 测试覆盖率 |
| 字段投影 | HIDDEN 不进入查询/排序/导出/日志 | 字段测试 |
| 多角色 | 行范围并集；字段不跨绑定拼接 | 组合测试 |
| 撤权 | auth_version 递增；旧会话下一请求拒绝 | 集成测试 |
| 导出 | 独立能力、字段白名单、行数上限、水印、审计 | 导出测试 |
| 高危 | 双人/强确认；职责分离；JIT/break-glass | 审计记录 |
| 访问复核 | 高危季度、普通年度；未确认默认收回 | 复核报告 |
| 离职 | 账号、会话、token、代理、导出任务全部失效 | 离职 E2E |

## 08 迁移与切换计划

### 8.1 迁移映射

TABLE: 源数据到目标模型
| 源数据 | 目标 | 关键规则 | 对账 |
| 员工 Excel/旧系统 | Person + Employment | 唯一键、去重、工号、入职日、状态 | 人数、在职数、部门分布 |
| 部门表 | OrgUnit + Closure | 父子关系、有效期、虚拟部门 | 部门数、层级、人数 |
| 岗位表 | Position + Assignment | 岗位目录与占用分离 | 岗位数、编制、占用 |
| 合同 | EmploymentTerms + Document | 版本、起止、续签、试用期 | 合同数、到期数、版本 |
| 假期余额 | LeaveLedgerEntry 期初 | 按类型、有效期、来源 | 期初+发生=期末 |
| 工资历史 | PayrollRun/Payslip YTD | 年度、期间、项目、净额 | 人头、应发、实发、YTD |
| 考勤历史 | AttendanceDay + Punch | 只迁必要期间；原始记录可追溯 | 出勤天数、异常数 |
| 用户/角色 | User + AuthorityBinding | 角色模板映射、例外审批 | 账号数、权限差异 |

### 8.2 三次彩排

TABLE: 彩排要求
| 轮次 | 数据 | 目标 | 退出标准 |
| 第 1 轮 | 全量脱敏数据 | 验证映射、性能、脚本 | 源记录 100% 可追溯；错误清单可解释 |
| 第 2 轮 | 全量 + 增量 | 验证冻结窗口、增量同步 | 增量≤30min；人数/工资对平 |
| 第 3 轮 | 生产式最终数据 | 验证 cutover runbook | 冻结≤4h；回滚≤60min；业务签字 |

### 8.3 分域切换

TABLE: 切换波次
| 波次 | 范围 | 前置条件 | 回滚点 | 不可回滚项 |
| W1 | 组织、人员、合同 | R1 验收；迁移彩排 3 通过 | DB 快照、功能开关 | 已发通知、已签合同 |
| W2 | 考勤、假勤 | 一个完整周期账本平衡 | 功能开关、旧系统只读 | 已审批假期、已结算 |
| W3 | 工资 | 两轮平行、财务/HR 签字 | 旧系统只读、批次号 | 已发工资、已报税 |
| W4 | 招聘、绩效、培训、自助、报表 | R4 验收；UAT 签字 | 功能开关 | 已发 Offer、已确认绩效 |

### 8.4 回滚 runbook 要点

- 触发条件：Sev1、工资差异超阈值、权限泄露、迁移对账失败。
- 回滚决策人：交付经理 + 技术负责人 + 业务 owner；工资相关加财务。
- 回滚步骤：停止写入 → 快照/PITR → 关闭功能开关 → 路由回旧系统 → 通知用户 → 对账 → 复盘。
- 回滚后必须核对：已发工资、已签合同、已发通知、用户新录入数据、外部系统回执。
- 回滚不能覆盖的事项，转入更正/冲正/人工对账流程。

## 09 质量、安全与合规验收

### 9.1 安全验收

TABLE: 安全验收项
| 项 | 标准 | 证据 |
| 认证 | OIDC SSO 主通道与本地兜底均可登录；特权与薪酬角色强制 MFA（TOTP/WebAuthn）；短信/邮件验证码不作第二因子 | 登录与 MFA 测试 |
| 会话 | HttpOnly/Secure/SameSite Cookie + CSRF；标准 12h/30min，特权 8h/15min；每个请求校验 `auth_version` | 会话与撤权测试 |
| 撤权 | 离职、转岗、改密、设备丢失后旧会话下一请求 403；代理审批与导出任务同步失效 | 离职 E2E、旧 token 测试 |
| 授权 | 默认拒绝、每请求校验、IDOR 负测；无 `condition_json` 与 CUSTOM SQL | 负测报告 |
| 字段 | 列级加密、默认遮罩、导出白名单；HIDDEN 不进入 WHERE/ORDER BY/日志 | 字段测试 |
| break-glass | 双人、30 分钟、理由、全量告警、自动过期；无永久超级管理员 | 审计记录 |
| 文件 | 私有桶、短效签名、病毒扫描、下载鉴权 | 下载测试 |
| 审计 | 只追加、敏感访问留痕、无明文；保留期策略可执行 | 审计样本、保留矩阵 |
| 依赖 | 无高危漏洞 | SCA 报告 |
| 配置 | 生产无调试、无默认口令、密钥由 KMS 管理并年度轮换 | 配置核查 |
| 备份 | 加密、异地、恢复演练；RTO≤2h、RPO≤15min | 演练报告 |

### 9.2 合规验收

TABLE: 合规检查
| 项 | 要求 | 证据 |
| 字段清单 | 字段—目的—依据—角色—保留期五元组 | 数据地图 |
| 告知 | 招聘/入职/在职/离职分层告知；同意不与劳动合同捆绑 | 告知文本与记录 |
| 敏感信息 | 单独评估、最小授权、列级加密、访问审计 | PIPIA 记录 |
| 保留/删除 | 按 6.5 保留矩阵执行；法务保全优先；删除 dry-run 与审批 | 保留策略、删除记录 |
| 工资合规 | 累计预扣、社保/公积金基数、舍入与法定参数版本可追溯 | 参数版本、平行报告、签字 |
| 年休假 | 法定下限不被配置覆盖；未休折算符合条例与公司规则 | 假期规则测试 |
| 导出 | 目的、审批、水印、有效期、审计 | 导出记录 |
| 跨境 | 盘点数据流；必要时评估/合同/认证 | 数据流图与评估 |
| 权利响应 | 查询/更正/删除/撤回工单 | 工单样本 |

### 9.3 UAT 场景

TABLE: UAT 核心场景
| ID | 场景 | 通过标准 |
| UAT-01 | 新员工入职到首月工资 | 档案、任职、合同、考勤、工资全链路正确 |
| UAT-02 | 调岗未来生效 | 到期前旧岗，到期后新岗，历史不变 |
| UAT-03 | 合同续签 | 新版本生效，旧合同可审计，提醒不重复 |
| UAT-04 | 跨夜班次打卡 | 考勤日归属正确，加班计算正确 |
| UAT-05 | 请假占用/撤销/结算 | 账本平衡，无重复退款 |
| UAT-06 | 工资封存后更正 | 原批次不变，更正批次可对账 |
| UAT-07 | 离职结算 | 最后工资、未休年假、账号冻结正确 |
| UAT-08 | 跨公司调动 | 工龄/年假/社保/工资分段正确 |
| UAT-09 | 权限越权 | 所有拒绝路径正确，提示可理解；旧会话与代理审批同步失效 |
| UAT-10 | 仪表盘与下钻 | 数字、列表、导出口径一致 |
| UAT-11 | 工资累计预扣与舍入 | 跨月累计、专项扣除、更正补发后税额正确，差异可解释 |
| UAT-12 | 身份与会话 | SSO 登录、本地兜底、MFA step-up、break-glass 与撤权全部符合定稿参数 |
| UAT-13 | 保留期与删除 | 工资、合同、日志按矩阵保留；法务保全命中时不删除；删除有审批与证据 |

## 10 项目治理

### 10.1 RACI

TABLE: RACI
| 事项 | A | R | C/I |
| 范围/预算/go-no-go | 项目发起人 | 交付经理/产品 | 技术、QA、HR C；业务 I |
| 架构/API/权限内核 | 技术负责人 | 前后端 | 安全、QA C；PM I |
| 安全/隐私 | 安全负责人 | DevOps/后端 | HR/法务 C；发起人 I |
| Release gate | 交付经理 | QA/技术/DevOps | 产品、HR C；发起人 I |
| UAT/业务验收 | HR 业务 Owner | HR 专家/QA | PM、技术 C；发起人 I |
| 迁移/切换 | 交付经理 | 数据/DevOps/HR | QA、安全 C；发起人 I |
| Hypercare | 技术负责人 | DevOps/QA/后端 | PM/HR C；业务 I |

### 10.2 变更控制

- W2 锁定范围基线；每周 CCB。
- 变更单必须含：价值、工作量、依赖、风险、测试影响、回滚方案。
- 1 进 1 出，按“工作量 + 风险 + 测试成本”等价交换。
- 安全、工资正确性、迁移/UAT 不得换出。
- W20 后只接受 Sev1/监管变更；无净增范围，否则正式延期。
- 技术债预算：每 sprint 10–15% 容量；每个 release 1 周硬化期。
- P0/P1 安全、性能、数据一致性债务阻断发布。

### 10.3 会议节奏

TABLE: 治理会议
| 会议 | 频率 | 参与 | 产出 |
| 站会 | 每日 | 交付团队 | 阻塞、风险、当日目标 |
| 架构评审 | 每周 | 技术负责人、后端、安全 | ADR、接口/模型决定 |
| CCB | 每周 | 发起人、产品、交付、技术、QA | 范围变更决定 |
| 质量门禁评审 | 每个 release | QA、技术、DevOps、业务 | Go/No-Go 记录 |
| 业务 UAT 例会 | 每周 | HR 专家、关键用户、QA | 缺陷、签字、培训 |
| Hypercare 战会 | 每日（前 2 周） | 技术、QA、DevOps、HR | 事件、对账、行动项 |

### 10.4 设计决策与组织签认

设计决策已在解决方案附录 E 定稿（D-01–D-25），本计划不再保留“待选方案”。这里只登记两类事项：需要组织签认的资源/范围/合规授权，以及必须按周落成 ADR 的不可逆决策。

TABLE: 组织签认排期
| # | 签认事项 | 结论编号 | 截止 | 签认人 | 阻断的 release |
| 1 | V1.0 范围基线与 Must/Should/Could/Won't | S-01 / D-19 | W2 | 发起人/产品 | R1 |
| 2 | 30 周 P50 / 38–44 周 P80 与 8 FTE 基线 | S-02、S-07 / D-22 | W2 | 发起人 | 全部 |
| 3 | 发布门禁、豁免权与变更控制 | S-03 / D-20、D-24 | W3 | 发起人/QA | R0 后所有 release |
| 4 | 试点范围、分域切换与外部集成边界 | S-04 / D-21 | W2 | 发起人/HR | 切换 |
| 5 | 薪酬法定口径与财务/法务责任人 | S-05 / D-14、D-15、D-18 | W8 | 财务/HR/法务 | R3 平行运行 |
| 6 | 迁移源、数据 owner、保留期与法务保全 | S-06 / D-18、D-23 | W4 | 发起人/HR/法务 | 迁移彩排 1 |
| 7 | 身份、SSO、MFA 与 break-glass 托管 | S-08 / D-09–D-11 | W3 | 发起人/安全 | E02 验收 |

TABLE: 不可逆决策与 ADR 落成时点
| ADR | 决策编号 | 主题 | 为什么难逆 | 写 ADR 截止 | 冻结/验收 |
| ADR-0001 | D-08 | 模块化单体与模块边界 | 边界一旦被跨表写入破坏，拆分成本高 | W1 | R0 |
| ADR-0002 | D-01、D-02 | Person–Employment–Assignment 与法人边界 | 历史、工资、假勤、报表都依赖 | W3 | W4 冻结模型 |
| ADR-0003 | D-03、D-17 | 双时间、业务日历、时区与年休假基线 | 时间语义错会导致全员考勤与假期错 | W4 | R2 验收 |
| ADR-0004 | D-04 | Capability × Anchor 授权 | 一旦有真实授权数据，改模型要迁移全部绑定 | W3 | R0 后持续 |
| ADR-0005 | D-05 | Case–Decision–Effect | 状态与副作用契约被所有模块依赖 | W5 | R1 验收 |
| ADR-0006 | D-06、D-17 | 假勤账本与关账 | 已结算假期不可回滚，只能冲正 | W7 | R2 验收 |
| ADR-0007 | D-06、D-14、D-16 | 工资批次、更正与计算边界 | 已发工资、已报税不可逆 | W10 | R3 验收 |
| ADR-0008 | D-07 | Outbox、幂等与事件契约 | 历史事件不可丢，消费者依赖契约 | W6 | R3 前 |
| ADR-0009 | D-12、D-13 | 文件、敏感数据、密钥与隐私 | 加密与保留策略改造成本极高 | W6 | R2 前 |
| ADR-0010 | D-21、D-23 | 迁移与切换 | 切换边界与 source of truth 直接影响回滚 | W10 | 彩排 1 前 |
| ADR-0011 | D-25 | 部署、备份与可观测 | 数据驻留与 RTO/RPO 是合同与合规前提 | W4 | R0 |
| ADR-0012 | D-19、D-24 | 配置边界与能力发布 | 配置一旦成为第二套程序，收回极难 | W8 | R4 前 |
| ADR-0013 | D-09、D-10、D-11 | 身份、会话、MFA、SSO 与 break-glass | 账号与撤权模型是所有模块的安全基础 | W3 | R0 |
| ADR-0014 | D-14、D-15、D-16 | 薪酬计算、舍入与法定参数 | 舍入与累计口径错会直接错发 | W8 | R3 |
| ADR-0015 | D-18 | 保留期、删除与法务保全 | 误删不可恢复，超期保留违反最小必要 | W8 | R6 前 |

> NOTE: 这些决策之所以“难逆”，不是因为技术难，而是因为一旦有真实工资、合同、授权数据，改模型就等于改历史。ADR 必须在表列截止日前合并；逾期未写 ADR 的实现视为架构违规，由架构评审阻断。

## 11 风险登记册

TABLE: Top 12 风险
| # | 风险 | 概率/影响 | 触发信号 | 缓解 | Owner |
| 1 | 权限/字段旁路导致越权泄露 | 高/高 | 端点无负测、导出明文、403 异常 | 权限内核、端点清单、自动化负测、统一脱敏 | 技术负责人/安全 |
| 2 | 工资算错/法定口径错 | 中/高 | 平行差异、手工调整增多 | 规则版本、2 轮平行、双人复核、法务签字；累计预扣与舍入用例（D-14/D-15） | 薪酬负责人/HR |
| 3 | 跨模块状态不一致/重复生效 | 中/高 | 重复审批、队列重试、账实不符 | Decision/Effect、幂等、对账、E2E 契约 | 后端负责人 |
| 4 | 迁移数据脏/历史缺失 | 高/高 | 重复工号、映射失败、人数不平 | 数据 profiling、三次彩排、逐项对账 | 交付经理/数据 owner |
| 5 | 切换失控 | 中/高 | UAT 晚、门禁被豁免、试点故障 | 试点+分域、工资影子、go/no-go、回滚演练 | 交付经理 |
| 6 | 有效期/时区/跨夜考勤错误 | 高/高 | 未来生效错、跨夜归属错 | Clock 抽象、T-1/T/T+1、快照、业务日历 | 后端/QA |
| 7 | 外部集成/设备/SSO 延期 | 高/中 | 无凭据、厂商排期 | 适配层、CSV/手工兜底、独立验收；OIDC 本地兜底（D-09） | 产品/集成 |
| 8 | 范围蔓延/8 人容量不足 | 高/高 | backlog 增长、门禁连续滑 | 范围围栏、CCB、1 进 1 出、缓冲 | PM/发起人 |
| 9 | 性能/容量不达标 | 中/高 | P95>500ms、工资批次超窗 | 早期压测、索引、异步、容量演练 | 技术负责人/DevOps |
| 10 | QA 单点/关键人依赖 | 中/高 | 回归>4h、缺陷逃逸 | 开发自测、自动化、结对、交叉培训 | QA/技术负责人 |
| 11 | 隐私合规/敏感数据泄露 | 中/高 | 日志/导出明文、留存不清 | 数据地图、最小化、加密、DLP、保留矩阵与法务保全（D-18） | 安全/HR/法务 |
| 12 | UAT/业务专家不可用 | 高/中 | HR 专家投入<50%、签字延迟 | 提前锁定日历、业务 owner、UAT 前置 | 产品/HR owner |

## 12 上线与 Hypercare

### 12.1 Hypercare 指标

TABLE: 90 天 Hypercare
| 指标 | 目标 | 频率 |
| Sev1 | 0 | 实时 |
| MTTA / MTTR | ≤15min / ≤4h（Sev1） | 实时 |
| 可用性 | ≥99.9% | 每日 |
| P95 / 错误率 | <500ms / <0.5% | 每日 |
| 工资按时率 | 100% | 每月 |
| 工资重大错发 | 0 | 每月 |
| 假勤账本未解释差异 | 0 | 每日 |
| 待办>24h 比例 | <1% | 每日 |
| 员工月活 | ≥90% | 每月 |
| 自助请假比例 | ≥80% | 每月 |
| 工资条查看率 | ≥95% | 每月 |
| 每百员工工单下降 | 较上线首月≥30% | 每月 |
| 变更失败率/回滚率 | <10% / <5% | 每月 |

### 12.2 退出 Hypercare 条件

- 连续 2 个工资周期零重大差异，业务与财务签字。
- Sev1=0；Sev2 在 SLA 内关闭。
- 账本、人数、工资、合同对账 0 差异。
- 关键用户培训完成；操作手册与 runbook 更新。
- 技术债、监控、告警、备份恢复演练通过。
- 发起人批准退出。

### 12.3 上线后 30/60/90 天复盘

TABLE: 复盘节奏
| 时间 | 关注 | 产出 |
| 30 天 | 稳定性、工单、权限拒绝、工资首月 | 修复清单、培训补强 |
| 60 天 | 使用率、流程效率、数据质量 | 流程优化、自动化 |
| 90 天 | 业务结果、维护成本、技术债 | Hypercare 退出、V1.1 路线 |

复盘不是为了写报告，而是为了决定下一季度把工程容量投到哪里：修数据质量、补自动化、减审批摩擦，还是继续加功能。建议每次复盘固定输出一张“系统健康卡”：工资准确率、账本差异、权限拒绝覆盖、待办年龄、变更失败率、工单趋势，用同一组指标对比 30/60/90 天，避免凭印象判断成败。

## 13 完成定义与证据

### 13.1 每个工作包的完成定义

- 代码：前端页面 + API + 迁移 + Service + Policy + 测试，不得只有页面。
- 权限：菜单、页面、按钮、数据范围、字段档位均标注并测试。
- 数据：正常、空状态、错误、并发、跨公司、历史有效期均验证。
- 文档：OpenAPI、数据库变更、权限资源清单、状态机、上线变更记录。
- 审查：格式、静态检查、单元/集成/E2E、代码评审全部通过。
- 证据：测试报告、截图/录屏、对账报告、Go/No-Go 记录归档。

### 13.2 最终验收证据包

TABLE: 验收证据
| 证据 | 内容 | 责任人 |
| 架构基线 | ADR、模块依赖图、数据模型 | 技术负责人 |
| 权限矩阵 | 能力目录、角色模板、负测报告 | 安全/后端 |
| 数据对账 | 迁移、账本、工资、人数 | QA/财务/HR |
| 测试报告 | 单元/契约/权限/时间/E2E/性能/混沌 | QA |
| 安全报告 | 渗透、SCA、配置、审计样本 | 安全 |
| 合规材料 | 数据地图、告知、PIPIA、保留策略 | HR/法务 |
| 运维手册 | 部署、备份、恢复、故障、回滚 | DevOps |
| 业务手册 | 管理员、HR、经理、员工 | 产品/HR |
| 培训记录 | 关键用户、管理员、HR | 产品 |
| 上线记录 | go/no-go、切换、Hypercare | 交付经理 |

## 14 附：前两周执行清单

> LEAD: 前两周不追求功能数量，只追求“骨架可运行、权限可拒绝、数据可追踪”。如果这两周没有形成真实链路，后面的模块都会变成演示。

### 14.1 W1 每日目标

TABLE: W1 执行
| 天 | 目标 | 产出 |
| D1 | 仓库、分支、环境、依赖锁定 | 可运行的 Laravel/Vue 骨架 |
| D2 | CI、静态检查、测试框架 | CI 全绿 |
| D3 | 认证骨架、用户/会话表 | 登录/登出/me |
| D4 | 统一响应、异常、request_id | API 基线 |
| D5 | Docker Compose、健康检查 | 本地一键启动 |

### 14.2 W2 每日目标

TABLE: W2 执行
| 天 | 目标 | 产出 |
| D1 | 能力目录、角色模板 | capability registry |
| D2 | authority_bindings、Authorizer 骨架 | 约束查询 |
| D3 | FieldProjector、字段档位 | 字段投影 |
| D4 | 权限负测基线 | 拒绝测试 |
| D5 | 数据 profiling、迁移源清单 | 数据地图 v1 |

### 14.3 第一周验收命令

```bash
composer install
php artisan migrate --seed
php artisan test
pnpm install
pnpm typecheck
pnpm lint
pnpm test:unit
docker compose -f deploy/compose/docker-compose.yml up -d
curl -s http://localhost:8080/v1/health | jq
```

如果这些命令不能在一台干净机器上复现，W1 就没有完成。不要用“我这里能跑”代替可复现的工程基线。

### 14.4 前两周的退出标准

前两周结束时，团队必须能回答四个问题：新环境能否从零启动？未授权请求是否被服务端拒绝？一次请求能否从日志追到数据库和审计？备份能否恢复？四个问题有一个答不上来，就不进入业务模块开发。

## 15 附：ADR 清单与模板

> LEAD: ADR 不是文档装饰。它把不可逆决策的假设、取舍和复审条件固定下来，避免项目进行到一半时靠记忆争论。ADR 的结论来自解决方案附录 E，不在这里重新论证。

TABLE: 必须产出的 ADR
| ADR | 决策编号 | 主题 | 截止 | 验收证据 |
| ADR-0001 | D-08 | 模块化单体与模块边界 | W1 | 架构测试通过，业务模块无跨模块 Model 引用 |
| ADR-0002 | D-01、D-02 | Person–Employment–Assignment 与法人边界 | W3 | 模型评审记录、迁移草案、跨法人测试 |
| ADR-0003 | D-03、D-17 | 双时间、业务日历、时区与年休假基线 | W4 | as-of 测试、T-1/T/T+1、跨夜与跨年用例 |
| ADR-0004 | D-04 | Capability × Anchor 授权 | W3 | 能力目录、七类 Anchor、负测基线 |
| ADR-0005 | D-05 | Case–Decision–Effect | W5 | 决策不可变、幂等、Effect 失败重试测试 |
| ADR-0006 | D-06、D-17 | 假勤账本与关账 | W7 | 账本恒等式、撤销/冲正、负余额用例 |
| ADR-0007 | D-06、D-14、D-16 | 工资批次、更正与计算边界 | W10 | 批次不可变、更正批次、两轮平行记录 |
| ADR-0008 | D-07 | Outbox、幂等与事件契约 | W6 | 契约测试、重复投递、死信演练 |
| ADR-0009 | D-12、D-13 | 文件、敏感数据、密钥与隐私 | W6 | 加密、短效下载、字段审计、数据地图 |
| ADR-0010 | D-21、D-23 | 迁移与切换 | W10 | source-of-truth 映射、彩排 1 对账 |
| ADR-0011 | D-25 | 部署、备份与可观测 | W4 | 一键部署、备份恢复、RTO/RPO 演练 |
| ADR-0012 | D-19、D-24 | 配置边界与能力发布 | W8 | 配置清单、能力发布流程、影响预览 |
| ADR-0013 | D-09、D-10、D-11 | 身份、会话、MFA、SSO 与 break-glass | W3 | 会话时长、撤权、MFA、break-glass 测试 |
| ADR-0014 | D-14、D-15、D-16 | 薪酬计算、舍入与法定参数 | W8 | 舍入用例、累计预扣、参数签字 |
| ADR-0015 | D-18 | 保留期、删除与法务保全 | W8 | 保留矩阵、删除 dry-run、保全例外 |

TABLE: ADR 模板必填字段
| 字段 | 要求 |
| 编号与标题 | 稳定编号，标题描述决策而不是问题 |
| 状态 | Proposed / Accepted / Superseded / Rejected，含日期 |
| 决策编号 | 引用解决方案附录 E 的 D/S/T 编号 |
| 背景与触发 | 为什么现在必须决定，适用规模与法规版本 |
| 决策 | 一句话结论，加必要的参数与边界 |
| 备选与取舍 | 至少两个备选，说明代价、风险与不选原因 |
| 影响 | 数据、接口、权限、工资、迁移、运维、培训 |
| 验证 | 对应测试、门禁、指标与证据文件 |
| 回退与复审 | 回退路径、触发条件、复审日期与 owner |

## 16 结论与签认

> IMPORTANT: 这份计划的核心不是“30 周做完所有页面”，而是“30 周内让企业能在一个真实月度里，用一套可解释、可对账、可回滚的系统完成人事、假勤和工资”。如果范围与深度冲突，优先保事实正确、权限拒绝、工资对账、迁移可回滚；页面可以后续迭代，真相不能返工。

### 16.1 执行前的四个确认

1. 设计决策已定稿：D-01–D-25 见解决方案附录 E；本计划不再保留待选方案。
2. 资源与范围已签认：S-01–S-08 在 W2–W8 完成；未签认项阻断对应 release，不阻断其他设计工作。
3. ADR 已排期：ADR-0001–0015 按第 15 章截止日落成；逾期实现视为架构违规。
4. 门禁可执行：安全、工资正确性、迁移对账三类门禁不可豁免；其他豁免书面、有到期日、最多一个 release。

### 16.2 签认页

TABLE: 设计与计划签认
| 角色 | 签认内容 | 姓名 | 签字 | 日期 |
| 项目发起人 | S-01、S-02、S-04、S-06、S-07；预算、范围、切换与迁移 owner |  |  |  |
| 产品负责人 | S-01；范围基线、Must/Should/Could/Won't |  |  |  |
| 技术负责人 | D-01–D-13、D-25 与 ADR-0001–0013；架构与部署基线 |  |  |  |
| HR 业务负责人 | S-04、S-05、S-06；组织、假勤、年休假与数据 owner |  |  |  |
| 财务/薪酬负责人 | S-05；薪酬口径、舍入、平行运行与保留期 |  |  |  |
| 安全/合规负责人 | S-03、S-08；身份、MFA、break-glass、隐私与门禁 |  |  |  |
| QA 负责人 | S-03；质量门禁阈值、负测与终验证据 |  |  |  |

TABLE: 签认后的变更规则
| 变更类型 | 处理 | 批准人 | 记录 |
| 设计结论变更 | 新建 ADR，更新附录 E 状态与日期 | 技术负责人 + 架构评审；涉及工资/权限/迁移加对应 owner | ADR、决策登记册、CCB 纪要 |
| 范围换入换出 | 1 进 1 出，评估工作量、风险与测试成本 | CCB | 变更单、范围基线 |
| 门禁豁免 | 仅非三类不可豁免门禁；书面、有到期日、最多一个 release | 发起人 + QA + 技术负责人 | 豁免单、release 记录 |
| 关键角色缺位 | 顺延计划，不压缩测试与迁移 | 发起人 | 资源变更记录 |
| 法规或外部接口变化 | 先更新参数版本与 ADR，再改代码 | 财务/法务/安全 + 技术 | 参数版本、ADR、回归证据 |

### 16.3 文档结束

本计划与《HRMS V1.0 解决方案：第一性原理重构》v1.1 配套使用。任务、门禁、ADR 与签认页可以调整，但不能降低三类不可豁免门禁，也不能绕开附录 E 的决策变更流程。开工前还需确认：决策基线已归档、S-01/S-02/S-04/S-07 已签、8 FTE 与 HR 专家投入已锁定、范围基线与 13.4 一致；前四项任一未完成，W1 应推迟，而不是用开发进度掩盖治理缺口。
