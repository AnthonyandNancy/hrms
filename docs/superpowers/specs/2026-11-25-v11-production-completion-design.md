# HRMS V1.1 生产完整性：解决方案设计

**状态：** 待用户确认  
**日期：** 2026-11-25  
**基线：** `main @ bdd20da`，真实 Laravel + MySQL Compose 栈  
**主需求：** `HRMS-V1.1实施书.md`（V1.1 为主）  
**辅助：** `docs/HRMS_V1.0_解决方案_第一性原理重构.md`、`docs/HRMS_V1.0_实施计划_30周路线图.md`  
**证据：** `docs/audit/2026-11-25-v11-gap-audit.md`

---

## 1. 要解决的问题

用户看到的是"超管很多页面进不去"，但审计证明至少四个独立故障叠加：

1. **授权生命周期故障**：合法改密后 `auth_version` 提升，既有 `authority_bindings` 没有迁移到新版本，`/me/capabilities` 归零。
2. **身份边界故障**：系统管理员没有 `employment`，但假期/考勤/工资条等 SELF 控制器把 `employmentId` 默认成 `0`，直接 404。
3. **契约故障**：真实后端返回 snake_case/分页对象/缺失字段；前端大量 `as never`、手写类型、无运行时校验，工资条页 100% 进入全局错误边界。
4. **覆盖故障**：V1.1 要求 17 菜单、140 业务页、14 平台模块、6 基础页、26 工作包；当前只有 20 条路由记录、18 个视图、12 个导航项，约 123 个业务页完全缺失。

**本质判断：** 当前系统是"架构骨架 + 少量纵切"，不是"V1.1 生产系统"。继续逐页补 UI 会在错误的契约、授权和身份前提上叠加返工。正确顺序是：**先冻结事实源与不变量，再按生产主链路分波交付**。

## 2. 用户已确认的边界

- **交付顺序：** 生产主链路优先（入职→合同→考勤假勤→薪酬→自助），再补绩效/招聘/培训/系统配置。
- **兼容策略：** 允许破坏性重构；目标是完全落地 V1.1，不为旧接口/旧表做无限兼容。
- **验收口径：** 全部任务自动完成，不需要 HR/财务签名，不区分关键/非关键；以自动化与真实栈为硬门禁。
- **管理员权限：** 管理员拥有至高权限，可以读取工资（含 P3 字段）；不再把“系统管理员默认不可读工资”作为限制。
- **环境边界：** 系统仅在内网部署，无外网暴露；环境变量/密钥不构成本轮交付门槛，但仍保留审计与可追溯。
- **页面/权限范围：** 以 `docs/audit/2026-11-25-v11-page-access-blueprint.md` 为唯一页面与权限分配蓝皮书；140 个业务页 + 14 个平台模块 + 6 个基础页 = 160 条注册表。
- **破坏性重构：** 允许重命名 API、重做 IAM 表、替换路由、重排菜单；必须保留数据迁移、对账与可回滚路径。

> 这些边界覆盖此前设计中的 C6 与 C7 建议：C6 改为管理员全权（含 P3），C7 改为内网 MFA 可选且不阻断交付；以用户决定为准。

## 3. 第一性原理：五个不变量

### I1｜单一契约事实源

OpenAPI 3.1 定义每个请求/响应；前端类型由 `openapi-typescript` 生成；关键响应在运行时用 Zod 校验；adapter 不得用 `as never` 或裸 `as unknown as` 绕过。  
**为什么：** 当前 30 处 `as never` 让编译器对真实漂移完全失明；工资条白屏就是直接后果。

### I2｜授权版本可重放

`auth_version` 只表示"当前有效授权快照的世代"。任何授权变更必须在同一事务内把**全部当前有效绑定**迁移/重写到新版本；改密、登出、MFA 注册只吊销会话，不改变授权语义。  
**为什么：** 一个版本号同时承担"会话失效"和"授权快照"两种语义，必然出现"改密后超管失权"这类系统性故障。

### I3｜身份边界显式

`User` 是账号；`Employment` 是雇佣关系；`SELF` 能力只在存在有效任职时可用。系统管理员的能力是治理能力，不是员工自助能力的替代。无任职时返回明确的业务错误 `NO_ACTIVE_EMPLOYMENT`，而不是 404 或白屏。  
**为什么：** 管理员不是员工是常态；把两者混在一起会让所有自助页面在生产环境对新管理员不可用。

### I4｜错误隔离

三层错误边界：App 级最后兜底；AppShell 级保留导航并展示路由错误；页面级 ErrorState 带 `request_id`。同一错误只呈现一次；路由切换自动清除边界错误；旧 chunk 加载失败可重试。  
**为什么：** 一个字段 `undefined` 不应清空整个工作区；用户必须能继续导航、复制错误编号、重试。

### I5｜覆盖可执行

页面注册表是唯一覆盖清单：每个页面声明 `path/name/component/capability/menu/group/requiredApis/e2e`；CI 与 V1.1 的 160 条注册表（140 业务 + 14 平台 + 6 基础）diff；缺一即失败。  
**为什么：** 当前没有可执行的覆盖对照，删掉页面测试仍全绿；必须让"缺失"在 CI 中可见。

## 4. 必须先裁决的文档冲突

V1.1 文档本身不是一份可直接编码的规范；以下冲突必须在 Phase 0 用 ADR 裁决，否则 160 条页面条目会各自解释一套权限和实体。

| 编号 | 冲突 | 建议裁决 | 影响 |
|---|---|---|---|
| C1 | 权限命名三套：`employees:view` / `hr.employee.read` / `people.employee.read` | **执行码保留现有 `people.employee.read` 等**；V1.1 的 `module:action` 仅作为 UI 资源别名映射；新增 `PermissionResourceCatalog` 做单一映射 | 菜单、按钮、API 同源；避免 160 条页面各写一套 |
| C2 | API 前缀：`/api/v1/hr/employees` vs `/v1/employees` | **部署前缀 `/v1` 不变**；资源路径统一为 `/v1/employees`、`/v1/org-units`、`/v1/time/*`、`/v1/payroll/*`、`/v1/reporting/*`；V1.1 的 `/api/v1/hr/*` 只作为文档示例，不实现别名 | 避免路由分叉与双倍测试 |
| C3 | 实体命名：`employees/departments/contracts` vs `persons/employments/org_units/employment_terms` | **保留 V1.0 时间化事实模型**；页面词汇映射到领域实体；禁止为页面名重建一套表 | 保护历史/as-of/账本/工资可复算 |
| C4 | 公司/租户/法人 | **`tenant_id` = 隔离边界；`legal_entity_id` = 雇主/合同/工资/税务边界；`company` 仅作为 UI 展示别名**；不新增第四套边界 | 多法人、跨公司调动、工资主体不被混在一起 |
| C5 | 数据范围枚举 | **以七类 Anchor 为可执行范围**（SELF/MANAGED/ORG_UNIT/ORG_SUBTREE/CASE/PAYROLL_RUN/LEGAL_ENTITY）；V1.1 的 DEPT/DEPT_TREE/COMPANY 映射到这些锚点；CUSTOM 只允许白名单模板，不允许任意 SQL | 列表/详情/导出/报表同一谓词 |
| C6 | 系统管理员工资权限 | **管理员拥有至高权限：wildcard 展开全部能力，包含 P3 薪资字段；系统管理员可读工资。** 保留 P3 读取/导出的不可变审计；SoD 风险只提示不阻断管理员 | 满足用户决定；避免“管理员看不到工资”的运营阻塞；审计仍可追责 |
| C7 | MFA 默认值 | **内网生产默认开启 MFA，但允许安装器一次性豁免并记录审计**；本地开发用显式环境变量关闭；不将 MFA 作为本轮交付的阻塞门槛 | 内网仍保留强认证；不因环境变量管理拖慢全量交付 |
| C8 | 页面范围 | **以 03A 140 业务页 + 03B 14 模块 + 6 基础页为唯一范围（160 条注册表）**；页面注册表逐项登记；03B 的“建议路径”统一到现行路由命名 | 覆盖可计算、可验收 |

## 5. 目标架构

```text
┌──────────────────────────────────────────────────────────────┐
│ SPA                                                          │
│  - router/registry.ts: 受控页面注册表（菜单+路由+权限+API）   │
│  - generated API types（openapi-typescript）                  │
│  - runtime schema validation（Zod）                           │
│  - 三层错误边界 + request_id + 重试/恢复                      │
├──────────────────────────────────────────────────────────────┤
│ API                                                          │
│  - OpenAPI 3.1 单一契约                                       │
│  - 每条路由：auth.session + csrf.header + capability          │
│  - 统一 envelope + 稳定错误码 + request_id                    │
├──────────────────────────────────────────────────────────────┤
│ Domain                                                       │
│  - Person–Employment–Assignment + as-of                       │
│  - Case–Decision–Effect + Outbox                              │
│  - 账本（假勤）+ 工资批次/更正 + 不可变审计                    │
├──────────────────────────────────────────────────────────────┤
│ Identity & Authority                                         │
│  - User ≠ Employment                                          │
│  - auth_version = 授权快照世代；绑定事务内重放                 │
│  - wildcard 展开全部能力（含 P3）；管理员可读工资      │
│  - P3 读取/导出仍写不可变审计；SoD 只提示不阻断          │
├──────────────────────────────────────────────────────────────┤
│ Platform                                                     │
│  - audit/outbox/idempotency/files/retention                   │
│  - build SHA / meta / health / smoke                          │
└──────────────────────────────────────────────────────────────┘
```

### 5.1 前端页面注册表

```ts
// frontend/src/router/registry.ts（示意）
export type PageSpec = {
  path: string
  name: string
  component: () => Promise<unknown>
  capability: string
  menu: { group: string; label: string; order: number; icon: string }
  requiredApis: string[]
  states: Array<'loading' | 'empty' | 'error' | 'forbidden' | 'partial'>
  e2e: string[]
}

export const PAGE_REGISTRY: PageSpec[] = [
  {
    path: '/hr/employees',
    name: 'employee-directory',
    component: () => import('@/views/hr/EmployeeDirectoryView.vue'),
    capability: 'people.employee.read',
    menu: { group: '组织与人事', label: '员工档案', order: 1, icon: 'User' },
    requiredApis: ['GET /v1/employees'],
    states: ['loading', 'empty', 'error', 'forbidden', 'partial'],
    e2e: ['employee-list', 'employee-forbidden'],
  },
  // ... 140 项
]
```

- 菜单树来自后端 `/v1/me/permissions`，但**只做可见性过滤**；真正拦截仍由路由守卫 + 后端能力校验共同完成。
- 组件只能来自注册表；后端返回的任意组件路径永远不能直接 `import()`。
- CI 校验：注册表数量 = 140 + 14 模块页；每个路径有组件文件；每个能力码在后端目录中存在；每个页面有至少一条真实 E2E。

### 5.2 授权版本重放

```text
事务开始
  lock user
  newVersion = user.auth_version + 1
  对每一条"当前有效"的 authority_binding:
      copy -> auth_version = newVersion
  对每一条"当前有效"的 user_role:
      copy/update -> auth_version = newVersion
  写入审计 authorization.snapshot.replayed
  user.auth_version = newVersion
事务提交
```

- 改密：只吊销会话，不提升授权版本；若要强制重新认证，使用会话 `reauth_required`，不改变授权。
- 角色/绑定变更：提升版本 + 重放；旧版本绑定保留为历史，不删除。
- 离职/禁用：提升版本 + 吊销会话 + 重放（或明确清空）；下一次请求 401。
- 并发：使用 `SELECT ... FOR UPDATE`；测试并行改角色/绑定不丢更新。
- 缓存：缓存键包含 `auth_version`；重放后旧键自然失效。

### 5.3 身份边界

```text
SELF 请求
  principal = resolve session
  employment = resolve active employment by user + business date
  if employment == null:
      return 409 NO_ACTIVE_EMPLOYMENT
  authorize capability with Anchor(SELF, employment)
```

- 管理员访问 `/workspace`、`/payroll/my-payslips`、`/leave`、`/attendance` 时，显示"当前账号未绑定员工，无法使用员工自助功能"，并给出"前往系统管理/员工档案"的安全跳转。
- 员工离岗/离职：`employment` 不再有效时，SELF 能力明确拒绝，不返回空数组伪装成功。
- 管理员如需查看员工数据，使用治理能力与目标员工锚点，不使用 SELF 页面。

### 5.4 错误隔离

```text
App.vue                 最后兜底：显示致命错误 + request_id + 刷新
  └─ AppShell.vue       路由级错误：保留侧栏/顶栏 + 页面错误面板 + 重试
       └─ Page.vue      页面级 ErrorState：局部失败、重试、返回安全页
```

- `App.vue` 的 `boundaryError` 监听路由变化并清除；同一路由内重复错误去重。
- 页面不得直接 `ElMessage.error` 处理 API 错误；统一走 `useApiFeedback`（带 401 跳转、403 返回安全页、422 字段错误、5xx request_id）。
- `MoneyText` 接受 `number | null | undefined`，缺失时显示"—"，绝不在渲染期抛异常；金额校验在 adapter/normalizer 层完成。
- 懒加载 chunk 失败：显示"页面版本已更新，请刷新"，并提供刷新按钮；不进入全局错误边界。

### 5.5 契约与生成

- OpenAPI 3.1 每个操作必须有 request/response schema、错误响应、权限扩展 `x-capability`。
- `pnpm openapi:generate` 使用 `openapi-typescript` 生成 `src/generated/api.ts`；`pnpm openapi:check` 在 CI 失败即阻断。
- Adapter 对每个操作使用生成类型；关键响应（登录、me/permissions、工资条、工资批次、审批详情、报表导出）用 Zod schema 校验；失败抛 `CONTRACT_MISMATCH` 并带 `request_id`。
- 禁止新增 `as never`；ESLint 规则 `@typescript-eslint/no-explicit-any` + 自定义规则扫描 `as never`，已有列表逐步清零。
- Mock adapter 从同一 OpenAPI 生成/校验，避免 mock 与真实形状漂移。

### 5.6 发布可追溯

- Docker 构建参数 `GIT_SHA`、`BUILD_TIME`；镜像 tag 为 `${RELEASE_ID}-${GIT_SHA}`，并记录 digest。
- `/v1/meta` 返回 `commit`、`build_time`、`api_version`；SPA 注入 `VITE_BUILD_SHA`，页面底部/关于页显示。
- 部署 smoke 检查：`/`、`/login`、`/workspace`、hashed asset、`/v1/health`、`/v1/meta`；三者 SHA 必须一致。
- 基础镜像固定 digest；MinIO 固定版本；SBOM 生成并归档。

## 6. 方案取舍

| 方案 | 说明 | 结论 |
|---|---|---|
| A. 只修工资条/错误边界 | 快速恢复截图页面 | 否：其余契约、授权、身份、覆盖问题仍在 |
| B. 只点亮 17 个一级菜单 | 快速消除"功能缺少"的观感 | 否：140 子页、权限、E2E 仍缺失 |
| C. 完全按 V1.1 重写 sys_* 表与 /system/* | 贴近文档字面 | 否：丢掉已实现的账本/时间/审批内核，迁移风险极高 |
| D. 保留 V1.0 内核，V1.1 作为产品层 | 契约/注册表/权限映射/真实栈门禁 | **推荐** |
| E. D + 分波主链路交付 | 先组织人事，再假勤薪酬，再绩效招聘培训，再平台 | **推荐组合** |

**最终选择：E。** 理由是：V1.1 的页面与功能范围必须全量落地，但 V1.0 已交付的时间化事实、账本、Case–Decision–Effect、能力锚点内核是正确且经过 818 个测试验证的资产。推倒重写会同时引入数据迁移风险、权限模型风险和工资重算风险，且不会让页面更可靠。正确做法是把 V1.1 当作产品层：补齐页面、接口、权限映射和真实栈验证，同时修复内核中的三个系统性缺陷（授权版本、身份边界、契约）。

## 7. 分波交付（与实施计划对应）

| 波次 | 范围 | 出口标准 |
|---|---|---|
| Wave 0 | 授权重放、身份边界、错误隔离、真实栈巡检 | 改密后能力不丢；无任职返回 409；12 条现有路由 0 错误边界；真实 E2E 可跑 |
| Wave 1 | OpenAPI 生成、Zod 校验、权限词汇、页面注册表、能力码补齐、管理员全权 | `openapi:check` 绿；`as never` 清零；160 条注册表建立；权限负测全绿 |
| Wave 2 | 组织人事：员工、部门、岗位、异动、合同 | 5 个菜单全部子页 + API + 权限 + E2E；入职→合同→异动→离职闭环 |
| Wave 3 | 假勤薪酬：考勤、请假、加班、出差、薪资 | 4+1 菜单全部子页；请假→考勤→工资；加班单一结算；封账/冲正 |
| Wave 4 | 绩效、招聘、培训 | 3 个菜单全部子页；招聘→Offer→入职；绩效申诉；培训幂等 |
| Wave 5 | 平台与系统管理：用户/角色/菜单/权限/数据范围/字段策略/日历/字典/文件/通知/审计/报表/自助 | 14 个平台模块；系统管理可视化授权；审计可追溯 |
| Wave 6 | 生产加固：性能、混沌、备份、安全、SBOM、全量验收 | 140 业务页 + 14 平台模块 + 6 基础页 100%；17 菜单门禁；真实栈全量 E2E；可追溯发布 |

## 8. 验收标准

### 8.1 页面级

每个页面必须同时满足：

1. 注册表有且仅有一条声明；组件文件存在；菜单/路由/能力同源。
2. OpenAPI 有请求/响应 schema；真实后端响应通过运行时校验。
3. 至少 4 类负测：未登录 401、无能力 403、越锚点 403/404、越字段或导出拒绝。
4. UI 五态：loading / empty / error / forbidden / partial；危险操作二次确认。
5. 组件测试使用真实 payload；真实栈 E2E 至少快乐路径 + 拒绝路径。
6. 敏感读取/导出/状态变更写审计，可按 `request_id` 追踪。

### 8.2 系统级

- `auth_version` 不变量：改密/角色/绑定/MFA/离职/禁用后，能力与绑定一致；并发变更不丢更新。
- 身份边界：无任职的账号访问 SELF 返回 409；管理员自助页显示明确空态。
- 契约：145+ 路由全部有 schema；`openapi:check` 绿；`as never` 0；Zod 运行时校验覆盖关键响应。
- 真实栈：安装器→登录→主链路→导出→审计→回滚；0 错误边界；0 非预期 401/403/404。
- 覆盖：140/140 业务页、14/14 平台模块、6/6 基础页、17/17 菜单、26/26 工作包有证据。
- 发布：镜像 SHA/digest、`/v1/meta`、SPA build SHA 一致；部署 smoke 通过。

## 9. 风险与缓解

| 风险 | 缓解 |
|---|---|
| 140 页一次性交付失控 | 分波交付；每波独立可上线；注册表驱动；不通过门禁不进入下一波 |
| 破坏性重构导致旧数据丢失 | 所有迁移 forward-only；先备份/彩排；数据迁移脚本 + 对账；保留历史表 |
| wildcard 全权影响审计 | 保留 P3 读取/导出/授权变更的不可变审计；SoD 风险提示；按 `request_id` 可追溯 |
| 内网环境变量管理松散 | 不将密钥管理作为本轮门槛；仍禁止把真实口令写入仓库；安装器生成独立密钥 |
| 真实 E2E 不稳定 | 固定 Compose、健康检查、隔离租户、重试；mock 仅快速反馈 |
| 文档冲突导致返工 | Phase 0 先写 ADR 裁决 C1-C8；冻结词汇/路由/实体映射 |
| 范围过大导致质量下降 | 不按日期压缩测试；每波必须过真实栈门禁；必要时延长工期而非降低标准 |

## 10. 不做（YAGNI）

- 不做通用 BPMN 设计器；保留有限状态机 + 模板/节点规则。
- 不做微服务拆分；保持模块化单体 + 合同/事件。
- 不做事件溯源主存；账本与审计足够。
- 不做任意 SQL/条件 JSON 数据范围；CUSTOM 只允许白名单模板。
- 不做多租户 SaaS 计费、原生 App、人脸识别算法、税务申报引擎、银行清算系统；保留适配层。
- 不为了"页面数量"造假数据、假接口、假 E2E；所有页面必须接真实 API。

## 11. 已确认的决定

用户已确认：

1. **管理员全权：** wildcard 展开全部能力，包含 P3 工资；管理员可读工资。
2. **页面与权限范围：** 以 `docs/audit/2026-11-25-v11-page-access-blueprint.md` 为准；140 业务页 + 14 平台模块 + 6 基础页。
3. **内网环境：** 环境变量/密钥不构成本轮门槛；仍保留审计与可追溯。
4. **破坏性重构：** 允许重命名 API、重做 IAM 表、替换路由、重排菜单；保留迁移/对账/回滚。

**实现顺序：** Wave 0（止血）→ Wave 1（契约/权限/注册表/能力码）→ Wave 2（组织人事）→ Wave 3（假勤薪酬）→ Wave 4（绩效/招聘/培训）→ Wave 5（平台/系统管理）→ Wave 6（生产加固/全量验收）。

本设计已并入实施计划：`docs/superpowers/plans/2026-11-25-v11-production-completion-implementation-plan.md`。执行时以 Wave 0 的授权快照、身份边界、错误隔离为第一优先级。
