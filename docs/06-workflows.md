# Workflow: Case–Decision–Effect

> 本文件对应实施计划 §4.4（E04）与 §4.9 的审批中心要求。它是**实现说明**，不是设计决策的重新论证；
> 决策本身见 [ADR-0005](adr/0005-case-decision-effect.md)。

## 1. 为什么不是"审批状态写业务表"

把 `status = APPROVED` 直接写进 `employments` 或 `payroll_runs` 会同时丢失三件事：

1. **谁在什么权限下做的决定** —— 事后无法还原当时的授权快照；
2. **决定与生效是两件事** —— 审批通过不等于业务已生效，未来生效日期、失败重试、补偿都需要独立状态；
3. **重放安全** —— 网络重试、消息重复投递、人工重试都可能触发两次副作用。

因此所有需要审批的业务都表达为 **Case（申请）→ Decision（决定）→ Effect（副作用）** 三段。

## 2. 三个对象

| 对象 | 表 | 语义 | 可变性 |
|---|---|---|---|
| Case | `cases` | 一次申请，承载 payload、模板快照、申请人与业务日期 | 状态机推进；payload 与模板快照不随模板改动而变 |
| Decision | `decisions` | 一次不可变的决定记录（APPROVE / REJECT / RETURN / WITHDRAW） | **只追加**，模型层拒绝更新与删除 |
| Effect | `effects` | 决定获批后要执行的一次副作用，含重试与幂等结果 | 状态推进（PENDING → APPLIED/FAILED）；不改写历史 |

**申请状态与生效状态是两条线**：`cases.status`（DRAFT/SUBMITTED/IN_REVIEW/APPROVED/REJECTED/WITHDRAWN）与
`cases.effect_status`（NONE/PENDING/SCHEDULED/APPLIED/FAILED/REVERSED）独立推进。审批中心同时显示两者，
不把"审批通过"显示成"已生效"。

## 3. 生命周期

```
DRAFT ──submit──> SUBMITTED ──assign──> IN_REVIEW ──decide──> APPROVED
   │                   │                    │                   │
   │                   │                    └──RETURN──> DRAFT    └──effect──> APPLIED / FAILED
   │                   └──withdraw──> WITHDRAWN
   └──cancel──> CANCELLED
```

- **提交即锁定载荷**：`template_snapshot_json` 在创建时快照模板版本，之后模板改版不影响在途与历史 Case。
- **决定不可变**：`DecisionImmutabilityTest` 断言更新/删除抛异常，动作序列连续且保留 actor 快照。
- **Effect 幂等**：每个 Effect 有唯一业务键，重复执行返回已有结果而不是再执行一次
  （`EffectIdempotencyTest`）。
- **失败可重试**：`POST /v1/effects/{id}/retry` 需要 `workflow.case.admin`，失败原因与重试次数留痕。

## 4. 模板解析是闭合映射

业务模块拥有自己的 case type 词汇（`LEAVE_REQUEST`、`ATTENDANCE_CORRECTION`），模板目录使用点号编码
（`leave.request`、`attendance.correction`）。二者通过 `CaseTemplateCatalog` **显式映射**：

| Case type | 模板编码 |
|---|---|
| `LEAVE_REQUEST` | `leave.request` |
| `ATTENDANCE_CORRECTION` | `attendance.correction` |
| `TRANSFER_REQUEST` | `transfer.request` |
| `CONTRACT_RENEWAL` | `contract.renewal` |
| `ONBOARDING_REQUEST` | `onboarding.request` |
| `OFFBOARDING_REQUEST` | `offboarding.request` |
| `PAYROLL_APPROVAL` | `payroll.approval` |

未映射的类型**失败关闭**（422），不回落 `generic.approval`，也不做字符串大小写折叠 —— 后者会让任何调用方
触达任何已发布模板。`workflow:seed-templates` 负责发布这些编码，且幂等。

## 5. 审批人解析

`CaseService::resolveAssignees()` 支持五种规则：

| 规则 | 解析方式 |
|---|---|
| `USER` | 模板内写死的用户 id |
| `MANAGER_OF_SUBJECT` | 经 People 拥有的 `EmploymentManagerDirectory` 契约解析被审批人的经理 |
| `ORG_HEAD` | 组织负责人 |
| `ROLE` | 该业务日期生效的角色成员（IAM） |
| `CASE_MEMBER` | Case 成员（申请人/审批人/抄送） |

解析不到审批人时**拒绝提交**（"Approval node has no eligible assignee."），而不是创建一个没人能审的 Case。
Workflow 不直接查询 People / IAM 的表，只依赖各自发布的契约。

## 6. 领域副作用（Domain Effects）

业务模块实现 `DomainEffectHandler`（`supports/apply/reverse`），Workflow 侧由
`DomainEffectHandlerAdapter` 适配。已注册的领域：

- **Lifecycle**：`lifecycle.onboarding`、`lifecycle.probation_confirm`、`lifecycle.assignment_change`、
  `lifecycle.terms_change`、`lifecycle.offboard`、`lifecycle.contract_renew`、`lifecycle.rehire`
- **Talent**：招聘 Offer 接受、绩效申诉、培训报名
- **Payroll**：`payroll.approval` 走 Case 决定后触发批次状态推进
- **Time**：`workflow.leave.reserve`（占位副作用；真正的账本操作由 Time 在提交时完成）

副作用在**调用方拥有的单一事务**内执行：业务事实、EFFECTIVE intent、outbox 事件一起提交或一起回滚。

## 7. 接口

| 方法与路径 | 能力 | 说明 |
|---|---|---|
| `GET /v1/cases` | `workflow.case.read` | 待办、已办、我发起、抄送 |
| `GET /v1/cases/{id}` | `workflow.case.read` | 详情、时间线、双状态 |
| `POST /v1/cases` | `workflow.case.create` | 创建（DRAFT） |
| `POST /v1/cases/{id}/submit` | `workflow.case.create` | 提交 |
| `POST /v1/cases/{caseId}/decisions` | `workflow.case.decide` | 决定（不可变） |
| `POST /v1/cases/{id}/reassign` | `workflow.case.admin` | 转派 |
| `POST /v1/effects/{id}/retry` | `workflow.case.admin` | 重试失败副作用 |
| `GET/POST /v1/workflow/templates` | `workflow.case.read` / `.admin` | 模板查询与创建 |

所有写接口需要 `Idempotency-Key`，并且全部在 OpenAPI 3.1 中定义（`capability:audit` 会校验无遗漏）。

## 8. 验证

```bash
cd backend
php artisan test --filter=DecisionImmutabilityTest
php artisan test --filter=EffectIdempotencyTest
php artisan test --filter=ReassignTest
php artisan test --filter=TimeWorkflowIntegrationTest   # 真实适配器 + 真实模板目录
php artisan test --filter=CommandsTest                  # 模板种子幂等
php artisan workflow:check                              # 已批准但无 Effect 的 Case 会报错
php artisan capability:audit && php artisan arch:test
```

## 9. 已知限制

- 部分业务模板的审批人规则仍依赖种子数据中的角色成员；生产上线前需按真实组织关系配置。
- `payroll.approval` 的重开审批人目前记录为 user id，而非独立 Workflow Case（见 ADR-0007）。
- 审批中心的批量操作阈值与移动端布局尚未实现（计划 §4.9 的后续项）。
