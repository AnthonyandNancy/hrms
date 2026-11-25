# ADR-0030: 命令闭环与 Effect 重试

- **状态：** Proposed — 2026-11-25
- **关联：** ADR-0005（Case/Decision/Effect）、ADR-0008（Outbox/幂等）、ADR-0028
- **复审触发：** 任一命令需要绕过 Effect；Effect 重试策略调整；新增外部副作用

## 背景

写操作当前由各控制器自行拼装：幂等、事务、审计、Case 创建、Effect 执行分散在 Controller/Service。结果是同类缺陷反复出现：合同终止与续签、加班结算、招聘转员工、福利发放都需要相同骨架，却各写一遍。

## 决定

1. 新增写操作统一使用 `Command` 定义：能力码、锚点、幂等、不变量、Effect、审计动作。
2. 执行顺序固定：认证 → 能力/锚点 → 校验 → 不变量 → 事务 → Case（可选）→ Effect/Outbox → 审计 → 返回。
3. Effect 失败不静默：写入 `effects.status=FAILED` 与 `failure_reason`，提供受权重重试端点；重试幂等。
4. 审批型命令的 Effect 只在 Decision 通过后执行；执行前业务事实保持不变。
5. 幂等键作用域 = `METHOD:/v1/path` + tenant + principal；重放返回原 body 与 `Idempotency-Replayed: true`；payload 不同返回 409 `IDEMPOTENCY_CONFLICT`。
6. 现有控制器不强制一次性迁移；新增集群必须使用命令闭环，触碰旧控制器时迁移。

## 后果

- 控制器变薄；新增命令只需声明不变量与 Effect。
- 审计与幂等只实现一次，减少漏审。
- Effect 可重试意味着必须定义补偿与最大重试次数；失败超过阈值进入人工队列。

## 验证

- `CommandEnvelopeTest`：重放、冲突、并发、Effect 失败重试、审计字段。
- 每个审批型命令有“通过但 Effect 失败”的测试，断言业务事实未变且可重试。
