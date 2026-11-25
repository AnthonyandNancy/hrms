# ADR-0028: 能力闭环、投影契约与平台内核

- **状态：** Proposed — 2026-11-25
- **决策：** V1.1 缺口补全的架构路线（用户已确认方案 A）
- **关联：** ADR-0004（能力×锚点授权）、ADR-0008（Outbox/幂等）、ADR-0015（保留与删除）、ADR-0024（超管全权与 P3 审计）、ADR-0026（页面注册表）、ADR-0027（页面权限与角色）
- **复审触发：** 任一新页面需要第二个“读模型”实现；任一命令需要绕过 Effect/Outbox；任一模块需要跨模块写表；投影新鲜度 SLA 变化

## 背景

V1.1 的 160 条页面注册表已全部有成熟视图，193 个 API 操作通过契约与真实栈审计。但页面目录中仍有 **63 处显式“后端接口待补”**：

| 域 | 缺口数 | 性质 |
|---|---:|---|
| People/Org | 7 | 缺读模型（文档列表、岗位人员、职级）、缺版本化命令（合同变更/终止/模板）、缺生命周期类型（借调） |
| Time/Payroll | 13 | 缺规则/排班/月结/设备读模型、缺加班申请与确认、缺调休投影、缺差旅政策/审批/费用、缺薪酬档案与调薪记录 |
| Talent | 23 | 缺绩效指标/目标/360/校准/结果/报表、缺招聘看板/预算/候选人导入/评估/沟通/人才库/转员工、缺培训计划/课程/签到/考试/反馈/档案/报表 |
| Platform | 20 | 缺角色创建、菜单 CRUD、数据范围与字段策略写入、福利、日历版本、字典参数、通知投递、备份运维、报表订阅/自定义/导出历史、公告审核/受众/已读/模板/归档 |

这些缺口并非同一种问题。若按页面逐个补端点，会得到 63 套各自实现的授权、字段投影、分页、幂等与审计。当前系统已经在 193 条路由上证明了这种做法的成本：每新增一个读页面，就要重复 `authorizer->constrain` + `FieldSet` + `ApiResponse::list` + `partial` 语义；每新增一个写命令，就要重复 `Idempotency-Key` + `DB::transaction` + `AuditRecorder` + Case/Effect。缺口补完后将超过 300 条路由，重复成本会变成维护负担与漂移源。

真正的根因不是“接口少”，而是 **缺少三类可复用内核**：

1. **写路径没有统一的能力闭环**：命令、不变量、幂等、审批、审计、副作用、补偿没有统一骨架，控制器各自拼装。
2. **读路径没有统一的投影契约**：as-of、授权范围、字段档位、分页、freshness、partial、下钻没有统一表示，导致报表、看板、列表三套口径。
3. **平台能力没有内核**：字典、参数、通知投递、文件密级、菜单展示覆盖、日历发布、报表订阅、运维任务分散在各域，无法被 63 项复用。

## 决定

### 1. 三条不可绕过的内核，先于 63 项落地

| 内核 | 位置 | 作用 | 谁必须使用 |
|---|---|---|---|
| **Command Envelope（命令闭环）** | `App\Shared\Commands` + `App\Shared\Workflow` | `Idempotency-Key` → 权限/锚点 → 校验 → 不变量 → 事务 → Case/Decision（可选）→ Effect → 审计 → Outbox | 所有新增写操作；现有写操作逐步迁移 |
| **Projection Contract（投影契约）** | `App\Shared\Projections` + `Reporting` | 统一 `as_of`、`anchor scope`、`field profile`、分页、`freshness`、`partial`、`drilldown` | 所有新增读页面；报表、看板、列表、导出共用同一查询 |
| **Platform Kernel（平台内核）** | `App\Modules\Platform` | 字典/参数、通知投递、文件密级与签名下载、菜单展示覆盖、日历发布、报表订阅、运维任务 | 所有域；平台不得反向依赖业务模块 |

三个内核都必须**代码拥有**（catalog/schema 在代码中），数据库只存租户数据与覆盖，不允许后台创建任意字段或任意 SQL。

### 2. 63 项归并为 12 个能力集群

不按页面实现，按“能力”实现。一个集群一次交付：命令 + 查询 + 权限 + 五态 + 负测 + 真实 E2E。

| # | 集群 | 覆盖缺口 | 关键不变量 |
|---:|---|---|---|
| K1 | 组织岗位完整性 | 岗位资格、职级目录、岗位人员、借调 | 岗位停用前无在岗；借调为 Assignment 而非改历史；职级目录代码拥有 |
| K2 | 合同全生命周期 | 合同台账读模型、变更/终止、模板、附件 | 合同版本只追加；终止需生效日；模板发布需版本；附件走签名下载 |
| K3 | 员工文档与档案完整性 | 员工附件列表/上传/下载/删除 | 文档元数据与文件对象分离；删除需保留类与法律保留校验 |
| K4 | 考勤规则与排班 | 考勤规则、排班、月结列表、设备 | 规则版本化；排班冲突检测；月结锁定后不可改；设备只读健康 |
| K5 | 加班与调休闭环 | 加班申请、确认、调休余额 | 申请工时≠确认工时；同一工时只结算一次（工资 XOR 调休） |
| K6 | 差旅与费用 | 差旅政策、审批决策、费用单据 | 政策版本化；审批走 Workflow；费用只登记不支付 |
| K7 | 薪酬档案与调薪 | 员工薪酬档案、调薪记录 | 薪酬档案按有效期版本化；调薪走敏感审批；金额整数分 |
| K8 | 绩效全周期 | 指标、目标、360、校准、结果、报表 | 权重合计=100；校准只改等级不改原始分；结果发布后不可改 |
| K9 | 招聘全漏斗 | 看板、预算、导入、评估、沟通、人才库、转员工 | 需求批准才可发布；转员工幂等；候选人 PII 受字段档位 |
| K10 | 培训全周期 | 计划、课程、签到、考试、反馈、档案、报表 | 报名容量与重复报名；签到幂等；学时只从完成记录派生 |
| K11 | 平台治理 | 角色创建、菜单 CRUD、数据范围、字段策略、福利、日历版本、字典参数、通知投递、备份运维 | 能力码代码拥有；数据范围白名单；字段策略不可放宽 P3；通知 outbox 幂等 |
| K12 | 报表与订阅 | 考勤/绩效培训/自定义报表、订阅、导出历史 | 报表不写业务表；订阅收件人再鉴权；导出行级授权与字段白名单 |

### 3. 读路径统一为 Projection Contract

```php
final class ProjectionRequest {
    public function __construct(
        public readonly int $tenantId,
        public readonly string $asOf,
        public readonly AnchorScope $scope,      // 由 Authorizer 编译
        public readonly FieldSet $fields,        // 由 FieldProfile 投影
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $filters,
        public readonly ?string $drilldown,
    ) {}
}

final class ProjectionResult {
    // items, page, page_size, total, as_of, freshness, partial, partial_reason, drilldown
}
```

所有新增读端点必须返回同一 envelope 字段；前端 `ResourcePage`/域工作台统一消费，不再各自判断 `items` 形状。

**投影新鲜度**：每个投影声明 `freshness`（`LIVE` / `MATERIALIZED` / `STALE`）与 `source_hash`。报表与看板必须同源：同一 `metric` 与 `drilldown` 使用同一 `MetricQueryBuilder` 谓词。

### 4. 写路径统一为 Command Envelope

```php
$command = Command::define('contract.terminate')
    ->capability('people.contract.manage')
    ->anchor(AnchorType::LEGAL_ENTITY)
    ->idempotent()
    ->invariant(ContractTerminationGuard::class)
    ->effect(ContractTerminationEffect::class)
    ->audit('people.contract.terminated');
```

- 幂等键作用域 = `METHOD:/v1/path` + 租户 + 主体；重放返回原 body 与 `Idempotency-Replayed: true`。
- 需要审批的命令生成 Case；Effect 在审批通过后执行，失败进入 `FAILED` 可重试，不允许“审批通过但事实未变”。
- 审计记录操作者、对象、前后值、`request_id`；P3 读取与导出单独写 `SensitiveAccessAuditor`。

### 5. 新增能力码与角色分配

- 63 项所需能力码**一次性**加入 `CapabilityCatalog`，代码拥有，命名遵循现有 `<domain>.<resource>.<action>`。
- 每个新页面的 `requiredApis`、OpenAPI `x-capability`、路由中间件、`endpoint_matrix.php`、角色模板必须在同一提交内一致；`capability:audit` 缺一即失败。
- 数据范围与字段策略写入属于 `iam.scope.manage` / `iam.field.manage`；超级管理员通配展开全部（ADR-0024），SoD 只提示不阻断管理员。

### 6. 跨领域参照与取舍

| 参照 | 机制 | 落点 | 不照搬的部分 |
|---|---|---|---|
| OS 内核/系统调用 | 稳定接口先于驱动 | Command/Projection/Platform 三内核 | 不做微内核拆分 |
| 编译器 IR | 前端只消费一种中间表示 | Projection envelope 统一形状 | 不做通用查询语言 |
| dbt 语义层 | 指标定义与物化分离 | `MetricQueryBuilder` + `ProjectionBuilder` | 不引入外部 dbt 运行时 |
| Kubernetes 声明式 API | spec/status、controller 收敛 | Effect 失败可重试、投影可重建 | 不做通用 CRD/operator |
| 银行复式记账 | 只追加、可对账 | 薪酬档案/调休/合同版本 | 不做事件溯源主存 |
| 航空检查单 | 强制门禁而非文档承诺 | 每集群 Definition of Done | 不增加人工审批层级 |

### 7. 二阶/三阶影响

**二阶（直接相邻系统）**

- 读端点统一后，前端 `ResourcePage` 与四个域工作台的 `recordFrom/rowsFrom` 兼容层可删除；报表、看板、下钻口径统一。
- 写端点统一后，`Idempotency-Key`、CSRF、审计、Case/Effect 只实现一次；控制器变薄。
- 平台内核让“字典/参数/通知/菜单覆盖/日历/订阅”可被 12 个集群复用，避免 63 项各自造配置表。

**三阶（组织与长期成本）**

- 新增页面从“改 8 个文件”降为“注册表 + catalog 条目 + 命令/查询复用”，边际成本下降。
- 授权与字段策略只有一处实现，安全审计可对单一入口取证，减少越权漏检。
- 投影可重建，报表争议可用 `source_hash` 对账；Effect 可重试，避免“审批通过但业务未生效”的静默错误。
- 代价：前期需要 3 个内核 + 5 个 ADR；若跳过内核直接补 63 项，6 个月后维护成本将随路由数线性上升，并重现本次根因。

### 8. 被拒绝的方案

| 方案 | 拒绝原因 |
|---|---|
| 逐页直连补 63 项 | 授权/字段/分页/审计重复 63 次；报表订阅与自定义报表无法成立 |
| 元数据驱动低代码全量 | 领域不变量泄漏到配置；批量赋值与越权风险；调试困难 |
| 引入 BPMN/微服务/事件溯源 | 与 ADR-0001/0005/0008 冲突，超出 YAGNI 边界 |
| 只补 P0 主链路 | 用户已明确要求 63 项全部真实 API |

## 后果

- 新增三个 Shared/Platform 内核与 5 个 ADR（0028–0032）。
- 12 个能力集群取代 63 个页面任务；每个集群必须一次交付命令/查询/权限/五态/负测/E2E。
- 现有 193 条路由不强制一次性迁移；新集群必须使用内核，旧路由在触碰时迁移。
- 页面注册表新增 `cluster` 字段，覆盖矩阵按集群统计，避免“页面数完成但能力缺失”再次发生。

## 验证

- `capability:audit` 零警告；每个新路由有 manifest 与 4 类负测。
- `ProjectionContractTest`：所有新增读端点返回统一字段与 `source_hash`。
- `CommandEnvelopeTest`：幂等重放、并发冲突、Effect 失败重试、审计。
- 12 个集群各自的真实栈 E2E；160 页审计零 5xx（语义 503 除外）。
- 覆盖矩阵 `--require-mature` 与 `--require-capabilities` 同时通过。

## 复审触发

- 任一集群需要第 4 个内核或跨模块写表。
- 投影新鲜度 SLA 无法满足报表/看板一致性。
- 新增页面超过 200 条或路由超过 400 条。
- 审计/合规要求从“可查询”升级为“外部不可篡改存证”。
