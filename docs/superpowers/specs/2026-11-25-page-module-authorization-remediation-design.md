# 页面独立化、超管授权与菜单可用性 — 根因与解决方案

- **状态：** Proposed — 待用户确认
- **日期：** 2026-11-25
- **适用版本：** HRMS V1.1（产品层）× V1.0（能力锚点内核）
- **关联文档：**
  - `HRMS-V1.1实施书.md`：§03、§03A、§03B、§04、§06.4、§09.3、§13.1、§13.4
  - `docs/HRMS_V1.0_解决方案_第一性原理重构.md`：§04、§07、§10、§13
  - `docs/HRMS_V1.0_实施计划_30周路线图.md`：§04、§05、§07
  - `docs/02-permissions.md`、`docs/05-ui-guidelines.md`
  - ADR-0004、ADR-0016、ADR-0024、ADR-0026、ADR-0027
  - `docs/audit/2026-11-25-v11-gap-audit.md`、`docs/audit/2026-11-25-v11-page-access-blueprint.md`
- **本轮边界：** 只做根因调查与方案/计划文档；不改业务代码、迁移、契约或配置。

---

## 1. 结论先行

三个现象不是三个孤立的 UI bug，而是同一组“边界被合并”的后果：

| # | 用户现象 | 直接原因 | 本质原因 |
|---|---|---|---|
| 1 | 员工、部门、合同等大量板块共用一个页面 | 160 条注册表虽有 160 个 `componentKey`，但 144 个业务/平台页面被 4 个“工作区壳”动态包装成同一组件；仅 10 个业务/平台页面有独立视图，6 个基础页复用固定视图 | **页面注册表只证明“键存在”，不证明“页面独立”**；覆盖门禁按字符串命中判成熟，架构上缺少“一页一组件一模块”的硬约束 |
| 2 | `demo.admin` 大量页面 403 | ① 演示数据锚定“法人 2”，前端请求固定“法人 1”，而系统本不该有法人概念；② 前端加载能力失败时静默降级为空数组；③ 前后端有 3 个页面的能力码不一致；④ 页面权限按“所需能力”隐式推导，没有“勾选页面”这一独立授权；⑤ 演示种子只写 `authority_bindings`，没有写 `user_roles`，演示账号在角色/页面授权链路上是“无角色”状态 | **系统仍按“多法人/多租户”实现，而业务只服务一个公司**；“页面权限、动作权限、数据范围、字段档位”四层又被压缩成一层能力码，前端把授权失败当成“无权限”展示 |
| 3 | 菜单可展开但无箭头/图标提示 | 父项只渲染业务图标；子项没有展开/收起指示；`aria-expanded` 有值但无视觉呈现 | **可展开性没有成为导航组件的显式状态与可测试契约**；视觉规范也漏写了这一条 |

**总根因：** 项目把“有注册表条目”“有真实接口”“门禁全绿”当成了“页面完成”和“授权正确”。注册表、能力码、组件、菜单、角色、门禁六个本应互相校验的事实源，实际只做了单向字符串匹配，没有做双向一致性校验。

**结论：** 不建议继续逐个补页面或给 `demo.admin` 临时放开权限。必须先修“页面边界 + 页面授权 + 导航契约”三个底座，再按模块拆分页面。否则每补一个页面都会复制同一个共享壳，每加一个角色都会继续用能力码猜页面，菜单问题也会在下一轮重构中复发。

---

## 2. 事实基线与证据

### 2.1 页面数量与组件数量严重不等

以 2026-11-25 工作树 `87084ad` 为准：

| 指标 | 实测值 | 含义 |
|---|---:|---|
| 注册表路由条目 | 160 | 数量契约满足 |
| 注册表 `componentKey` 唯一值 | 160 | 键唯一，但不等同组件唯一 |
| `frontend/src/views/**/*.vue` | 41 | 真实 Vue 文件总数（含表单、面板、基础页） |
| `pageComponents.ts` 的 `BASE_VIEWS` | 16 个显式装载器 | 其中 10 个是业务/平台独立视图，6 个是基础页 |
| 四个工作区壳承载 | 144 个业务/平台页面 | 37 个 People、39 个 TimePay、36 个 Talent、32 个 Platform；剩余 16 个由 `BASE_VIEWS` 显式导入 |
| People 壳承载 | 39 页 | `/hr/*` 全体员工、部门、岗位、异动、合同页面 |
| TimePay 壳承载 | 43 页 | 考勤、请假、加班、出差、薪资页面 |
| Talent 壳承载 | 36 页 | 绩效、招聘、培训页面 |
| Platform 壳承载 | 36 页 | 仪表盘、报表、公告、系统管理页面 |

关键代码：

- `frontend/src/views/people/index.ts:9-15`：为每个 key 创建 `defineComponent`，但 `setup()` 只返回 `h(PeopleWorkspace, { componentKey })`。
- `frontend/src/views/timepay/index.ts:8-17`：同样模式，全部渲染 `TimePayrollPage`。
- `frontend/src/views/talent/index.ts:6-12`：全部渲染 `TalentWorkspacePage`。
- `frontend/src/views/platform/index.ts:5-11`：全部渲染 `PlatformWorkspace`。
- `frontend/src/views/people/PeopleWorkspace.vue` 仅 459 行，却要按 39 份配置切换列表、详情、表单、树和合同视图；`PlatformWorkspace.vue` 达 4,790 行、约 206 KB，承担 36 个不同板块的读写与呈现。
- 逐条统计：160 个注册表条目中，16 个由 `BASE_VIEWS` 显式导入（其中 6 个基础页、10 个业务/平台独立视图），其余 144 个经四个域桶返回共享壳。也就是说，业务/平台页面中只有 10/154 是独立视图，144/154 是壳。注意：`BASE_VIEWS` 里的 10 个业务/平台条目自身也可能被四个域桶再次覆盖，实际“独立视图”以 `matureView()` 的解析顺序为准，门禁必须检查最终解析结果而不是仅查字符串。

这不是“复用组件”，而是**用 `componentKey` 做运行时分支的页面聚合器**。它带来四个直接后果：

1. 不同板块没有独立文件边界，任何改动都可能影响同壳其他页面。
2. 页面级权限、错误边界、埋点、懒加载粒度都只能落在壳上，无法按板块治理。
3. 测试只能证明“壳能按某个 key 渲染”，不能证明“该页面作为独立模块可用”。
4. 代码评审无法从目录结构看出 V1.1 的 140 个页面各自在哪里实现。

### 2.2 覆盖门禁把“键存在”误判为“页面成熟”

`scripts/audit_route_coverage.mjs:48-70` 的 `matureViewExists()` 只做两件事：

1. 在 `pageComponents.ts` 中找 `'componentKey':` 字符串；
2. 在对应领域目录的 `.ts` 文件中找 `'componentKey'` 字符串。

只要 `catalog.ts` 或 `definitions.ts` 里出现这个键，就判定为成熟页面。它从不检查：

- 是否存在与该页一一对应的 `.vue` 文件；
- 该 `.vue` 是否被直接静态或动态导入；
- 多个 `componentKey` 是否解析到同一个组件；
- 页面是否拥有自己的模块目录、API 适配、表单和测试。

因此 `evidence/v11-acceptance-report.md` 中的“160 页审计通过”只能说明 160 条路由能渲染出某个壳，**不能说明 160 个页面已按 V1.1 独立交付**。这是缺陷 1 被验收掩盖的制度性根因。

前端单测也偏弱：`frontend/tests/unit/routeRegistry.spec.ts` 主要断言数组长度、路径唯一、能力非空；`frontend/tests/unit/peoplePages.spec.ts`、`timePayrollPages.spec.ts`、`talentPages.spec.ts`、`platformPages.spec.ts` 多数是“按 key 挂载同一个壳并断言请求”。它们证明的是**配置驱动逻辑**，不是**页面模块完整性**。

### 2.3 超管“无权限”至少有五条独立成因

#### 成因 A（历史遗留）：错误的“法人上下文”导致演示数据与请求错位

> 这是**需要被删除的旧实现**，不是目标设计。ADR-0034 已裁定本系统只服务一个公司、不存在法人/租户。

真实库实测：

```text
legal_entities: 1 = ACME-CN, 2 = DEMO-CN
demo.admin 的 authority_bindings: capability_code='*', anchor_type='LEGAL_ENTITY', anchor_id=2
demo.hr 的 82 条绑定: 全部 anchor_id=2
org_units: 1=ACME-CN-HQ(法人1), 3..6/101..108=DEMO-*(法人2)
```

而 `frontend/src/api/http.ts:29-31` 写死：

```ts
export function legalEntityId(): number {
  return 1
}
```

`*` 不是“跨法人通行证”。后端 `CapabilityAuthorizer::anchorAllows()` 对 `LEGAL_ENTITY` 明确要求 `anchorId === grant->anchorId`；`WildcardGrantTest::test_a_wildcard_binding_does_not_cross_legal_entity_boundaries()` 也锁定了这一行为。因此旧实现下：

- 菜单能看到（`/me/capabilities` 不按法人过滤，返回全量 159 条能力）；
- 页面 API 带 `X-Legal-Entity-Id: 1` 时被拒（绑定在法人 2）；
- 用户看到“无权限”，实际是**作用域不匹配**。

**目标修复不是“正确选法人”，而是删除法人/租户维度**：`demo.admin` 的绑定统一为 `COMPANY`，前端不再发送 `X-Legal-Entity-Id`。

更严重的是，真实栈 E2E 自己用 `localStorage.setItem('hrms.legalEntityId', '2')` 规避了这个问题（`frontend/tests/e2e/real/cluster-journeys.spec.ts:71-75`），但 `http.ts` 根本不读这个 localStorage。也就是说测试里那行设置对真实请求无效，测试仍以 `demo.hr` + 法人 1 通过，恰恰掩盖了演示账号与默认法人的错位。

#### 成因 B：前端加载能力失败时静默降级为空数组

`frontend/src/api/http.ts:336-344`：

```ts
let capabilities: string[] = []
try {
  const caps = await unwrap(client.get('/me/capabilities'))
  capabilities = caps.capabilities.map((c) => c.capability)
} catch {
  capabilities = []
}
```

任何 403/500/网络错误/契约变化，都会被折叠成“已登录但零能力”。路由守卫随后把每个页面重定向到 `/forbidden`。用户无法区分：

- 真的没有授权；
- 会话已失效；
- 后端/契约故障；
- 旧实现的法人/租户上下文错位（ADR-0034 删除该维度）。

这违反 V1.1 §9.3“API 返回 403 必须给出可理解提示……不能因为前端缓存误判而吞掉错误”。

#### 成因 C：前后端页面能力码有 3 处不一致

逐路径比对 `frontend/src/router/registry.ts` 与 `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`：

| 路径 | 前端注册表要求 | 后端页面目录要求 | 后端实际路由门槛 |
|---|---|---|---|
| `/travel/policies` | `time.travel.read` | `time.travel.policy.read` | `time.travel.policy.read` |
| `/travel/approval` | `time.travel.read` | `time.travel.approve` | `time.travel.approve` |
| `/travel/expenses` | `time.travel.read` | `time.travel.expense.manage` | `time.travel.expense`（读）/`time.travel.expense.manage`（写） |

后果：

- 只有 `time.travel.read` 的角色：前端放行，后端 403；
- 有 `time.travel.policy.read` 的角色：后端允许，前端路由却因缺少 `time.travel.read` 而挡住；
- 菜单可见性与真实可操作范围不一致。

`capability:audit` 只检查路由中间件的能力码是否在目录中、是否被某个模板签发；它**不比对前端页面注册表**。所以这三处长期存在且门禁全绿。

#### 成因 D：演示种子没有写 `user_roles`，角色/页面授权链天然断开

`iam:seed-demo` 只创建 `authority_bindings`（`SeedDemoCommand.php:85-120`），没有为演示账号写 `user_roles`。真实库实测：`demo.admin`、`demo.hr`、`demo.manager`、`demo.employee` 的 `user_roles` 均为空。

这带来两个后果：

1. **无法按角色解释授权**：管理端“用户 → 角色”为空，角色成员数为 0；用户能看到菜单，却无法从角色页面追溯“为什么有这个能力”。
2. **无法落地页面授权**：`role_pages` 以 `role_code` 为键；如果演示账号没有 `user_roles`，即使新增页面授权表，`demo.admin` 也不会通过“角色 → 页面”解析到任何页面。上线后必须先补种子或提供迁移，否则超管仍会“有 `*` 但无页面”。

这也是“超管大量页面无权限”的第四条独立成因：不是能力不够，而是**授权主体链没有闭合**。

#### 成因 E：页面权限被“所需能力”替代，没有独立页面授权

V1.1 §4.2 和蓝皮书 §4.2 都要求“页面权限与动作权限分离”：

- 页面权限决定能否进入、菜单是否可见；
- 动作权限决定能否新建/编辑/导出/审批；
- 父菜单授权不等于子页面授权；
- “只有动作权限、没有页面权限”时，菜单不显示，直接访问 URL 被拦。

当前实现：

- 后端 `PermissionResourceCatalog` 是只读代码目录，每页只有一个 `required_capability`；`MeController::permissions()` 用 `actions[$required]` 推导可见页面（`MeController.php:109-131`）。
- 数据库只有 `roles`、`role_capabilities`、`user_roles`、`authority_bindings`，**没有 `role_pages` 或 `page_grants`**（`2026_11_23_100000_create_iam_tables.php:74-130`）。
- `RolePageAssignmentCatalog` 是代码内只读矩阵（`RolePageAssignmentCatalog.php:120-186`），只供测试和展示；`RoleAdminService` 创建/复制/替换角色时只写 `role_capabilities`，从不写页面（`RoleAdminService.php:238-251`）。
- 前端 `navigation.ts` 直接按页面 `capability` 过滤子项（`navigation.ts:168-175`），路由守卫也只查 `route.meta.capability`（`router/index.ts:105-108`）。

于是出现两个方向的错误：

1. **有动作能力就自动拥有同能力下所有页面**：例如拥有 `people.employee.read` 就同时看到员工列表、档案详情、教育经历、联系人、证照、附件、变更审批等 11 个页面，无法只授其中几个。
2. **有页面需求但没有对应“读取能力”就被整体挡住**：页面无法作为独立资源分配给角色，管理员在“角色管理”里也找不到“勾选页面”的持久化入口。

这不是“★ 号分配逻辑”的小问题，而是**权限模型缺了一层**。用 `*` 通配符本身不是错误——ADR-0016/0024 明确它是代码拥有、限时、可审计的管理员模板；错误在于把 `*` 展开出的动作能力当成了页面集合，并且没有让页面成为可独立授予的对象。

### 2.4 菜单无展开图标的直接证据

`frontend/src/layouts/AppShell.vue:196-210`：

```vue
<button class="nav-link nav-link--group" :aria-expanded="isGroupOpen(item)" ...>
  <el-icon class="nav-link__icon" aria-hidden="true">
    <component :is="icons[item.icon]" />
  </el-icon>
  <span class="nav-link__label">{{ GROUP_LABEL[item.group] }}</span>
  <span v-if="badgeFor(item)" class="nav-link__badge">...</span>
</button>
```

问题有三层：

1. **没有展开/收起图标**：唯一的 `<el-icon>` 是分组业务图标（如 People/Share），语义是“这是什么模块”，不是“可展开”。`aria-expanded` 对读屏可见，对视觉用户不可见。
2. **子项图标缺失**：注册表 154 个菜单子项全部 `icon: 'Menu'`；`navigation.ts` 的子项类型 `NavigationChild` 没有 `icon` 字段（`navigation.ts:24-30`），渲染时也不输出图标。
3. **活动键永远为空**：`AppShell.vue:84` 读 `route.meta.navKey`，但 `router/index.ts:22-28` 从未写入 `navKey`。因此 `isGroupOpen()` 永远只能靠用户手动展开；路由跳转后不会自动展开当前分组，`nav-link--active` 也不会在子项上出现。用户看到的是“点了菜单，分组没有高亮，也没有箭头”，进一步削弱可发现性。

这违反 `docs/05-ui-guidelines.md` §7（图标按钮必须有可访问名称、焦点可见）与 V1.1 §03B.4（菜单需有图标、默认展开与显示配置）。

### 2.5 为什么此前的“验收通过”没有发现

| 门禁 | 它实际证明的 | 它没有证明的 |
|---|---|---|
| `audit_route_coverage.mjs --require-mature` | 160 个 `componentKey` 字符串能在目录里找到 | 每个 key 是否有独立组件、是否多个 key 共用一个壳 |
| `routerRegistry.spec.ts` | 路由能解析、meta.capability 与注册表一致 | 页面是否可独立访问、菜单是否高亮、是否有展开指示 |
| 真实栈 160 页审计 | 每页有 `<h1>`、不落入 404/错误边界 | 页面是否真正属于该板块、403 是否被当作合法结果 |
| 真实栈 12 集群审计 | 集群主页面能调用其主接口 | 同集群其他页面是否独立；`demo.admin` 是否真的全通 |
| `capability:audit` | 每条路由有已知、已签发的能力码 | 前端页面能力码是否与后端页面目录一致 |
| `iam:seed-demo` | 创建了 `*` 绑定 | 旧实现按法人锚点；ADR-0034 改为 `COMPANY`，不再有默认法人 |
| 前端单测 | 配置驱动分支正确 | 真实演示账号从登录到每页的授权链 |

**一句话：现有门禁验证的是“配置自洽”，不是“系统对用户可用”。**

---

## 3. 实施书对照：哪些要求被违反

### 3.1 V1.1 的硬要求

| 来源 | 要求 | 当前状态 | 违反点 |
|---|---|---|---|
| V1.1 §03A.2 | 每个页面必须明确路由、权限码、查询范围、字段、按钮、五态、导入导出、审计 | 注册表有字段，但页面由共享壳承载 | 页面级规格无法落到独立模块 |
| V1.1 §03B.4 | 菜单树、路由注册名、排序、启停、图标、默认展开与显示 | 只有父项业务图标；子项无图标；无展开指示；默认展开失效 | 2.4 |
| V1.1 §04.1 | RBAC + Resource/Action + Scoped Grant + Field Policy + Ownership | 有 Capability × Anchor × FieldProfile；缺页面资源与动作的分层持久化 | 成因 E |
| V1.1 §04.2 | 页面权限与动作权限分离；父菜单不等于子页面 | 页面可见性由单能力推导 | 成因 E |
| V1.1 §04.7 | 角色管理可视化勾选页面/动作/范围/字段，并支持权限预览 | 角色只保存能力包；页面矩阵只读；无预览端点 | 成因 E |
| V1.1 §06.4 | `modules/<domain>/pages` 目录，禁止逐页重复权限判断、禁止静态菜单副本 | 4 个壳聚合 142 页 | 2.1 |
| V1.1 §09.3 | 菜单/路由来自“后端授权资源 + 本地受控注册表”交集；403 必须可理解 | 前端只取 capabilities；能力失败静默为空 | 成因 B |
| V1.1 §13.1（经 ADR-0034 修订） | 权限测试矩阵覆盖未登录、无页面、无动作、越锚点、越字段、越组织、旧会话 | 后端端点矩阵强；前端页面级与演示账号链路弱 | 2.5 |

### 3.2 V1.0 的硬要求

| 来源 | 要求 | 当前状态 | 违反点 |
|---|---|---|---|
| V1.0 §07.2 | 能力目录由代码拥有；授权绑定由数据表达；角色只是能力包 | 能力目录正确；角色只有能力包 | 页面资源未数据化 |
| V1.0 §07.3 | AuthorityBinding 是唯一行/字段作用域来源 | 正确，且应保留 | 不应为页面权限绕过 binding |
| V1.0 §07.7 | 角色模板、访问复核、时限授权 | 模板存在；页面分配不可编辑、不可预览 | 成因 E |
| V1.0 §10.3 | API 是契约；前端类型由契约生成 | 页面路由/菜单/权限仍靠手写注册表与字符串匹配 | 2.2 |
| V1.0 §13.3 | 架构适应度函数：契约漂移、权限拒绝覆盖、页面覆盖可执行 | 页面覆盖是字符串级；前后端能力码无对账 | 2.2/2.3C |

**裁决：** 保留 V1.0 的能力 × 锚点 × 字段内核，不另起一套 RBAC/DataScope 表；在 IAM 内增加“页面资源 + 页面授权”这一层，并让页面权限只决定进入/菜单，动作仍由能力中间件强制。这样既满足 V1.1 的产品层要求，也不破坏 V1.0 的安全内核。

---

## 4. 目标架构

### 4.1 四层权限模型（从“一层”恢复为“四层”）

```text
用户 User
  └─ 角色 UserRole（有效期、锚点、原因）
       ├─ 页面授权 RolePage（page_code）        ← 新增：决定菜单/路由可见
       ├─ 动作授权 RoleCapability（capability_code + anchor + field_profile）
       │    └─ AuthorityBinding（用户 × 能力 × 锚点 × 字段 × 有效期）
       └─ 数据范围（由 binding 的 anchor 表达，不另造第二套）
```

判定顺序（硬拒绝优先）：

1. 认证与会话有效；
2. 用户启用、`auth_version` 匹配；
3. **页面授权**：请求页面 `page_code` 是否被任一有效角色授予（`public` 页除外）；
4. **动作授权**：该页面所需动作能力是否通过 binding 编译；
5. **数据范围**：binding 的 anchor 是否覆盖目标行；
6. **字段档位**：P0–P3 投影与导出；
7. 对象级 SoD（工资审批等）。

关键点：

- **页面授权不替代动作授权**：有页面无动作 → 可进入但按钮隐藏，直接调 API 仍 403。
- **动作授权不自动产生页面授权**：有 `people.employee.read` 不代表能看到 11 个员工子页；管理员必须显式勾选页面。
- **`*` 的语义不变**：仍是代码拥有的管理员模板，展开为全部能力；同时 `SUPER_ADMIN`/`SYSTEM_ADMIN` 的页面集合显式写为全部 160 页，而不是靠“能力→页面”的隐式推导。这样“超管全通”是可审计的页面授权事实，不是字符串通配的副作用。
- **锚点边界改为 COMPANY**：页面授权不携带数据范围；数据范围永远来自 binding。ADR-0034 删除 `LEGAL_ENTITY` 锚点，`*` 绑定到 `COMPANY`（`anchor_id=null`、`anchor_key=0`），覆盖整个单公司实例。
- **授权主体链必须闭合**：每个演示/生产管理员都必须同时有 `user_roles` 与 `authority_bindings`；页面授权解析走 `user_roles → role_pages`，动作授权解析走 `authority_bindings`。种子、安装器和角色分配端点都必须在同一事务写两套事实，禁止只写其一。

### 4.2 页面作为一等资源

复用现有 160 个 `page_code`（前端 `name` 与后端 `page_code` 已 160/160 完全一致），不新建第二套页面表：

```text
permission resource (code-owned, read-only)
  page_code, path, menu_group, menu_label, order, icon,
  required_capability, actions[], required_apis[], layer, wave

role_pages (role-scoped, writable; single-company instance)
  id, role_code, page_code, created_at
  unique(role_code, page_code)

user_roles (existing)
  ...
```

说明：

- `PermissionResourceCatalog` 继续是**代码拥有的页面语义目录**，不允许 UI 新建 page_code（与能力目录同原则）。
- `role_pages` 只存“角色被授予哪些页面”，不存路径、组件、能力；页面语义变化不污染授权数据。
- `SUPER_ADMIN`/`SYSTEM_ADMIN` 初始化时写入全部 160 条 `role_pages`；`HR_ADMIN` 等模板按蓝皮书矩阵写入。
- 自定义角色创建/复制/替换时，`RoleAdminService` 与能力包在同一事务写 `role_pages`，并重放受影响用户的授权快照。
- `MeController::permissions()` 返回 `pages` 与 `menus` 时，先按 `role_pages` 过滤，再用 `required_capability` 做二次校验（默认拒绝）；菜单分组只是容器，不因父项存在而放行子页。
- 前端路由守卫从“查 `route.meta.capability`”改为“先查 `page_code` 是否在会话页面集合，再查能力用于按钮/动作”；`/me/permissions` 失败不得降级为空，必须显式错误态。

### 4.3 页面模块边界

目标目录（V1.1 §6.4 的落地版）：

```text
frontend/src/modules/
  people/
    pages/
      EmployeeListPage.vue
      EmployeeCreatePage.vue
      EmployeeDetailPage.vue
      EmployeeEducationPage.vue
      EmployeeContactsPage.vue
      DepartmentTreePage.vue
      DepartmentListPage.vue
      DepartmentEditorPage.vue
      PositionCatalogPage.vue
      ContractLedgerPage.vue
      ...
    components/        # 本模块共享的表格、表单、抽屉
    api/               # 本模块的读/写适配器（仍走统一 http/resourceClient）
    types.ts
  timepay/
    pages/
      AttendanceMyPage.vue
      AttendancePunchesPage.vue
      LeaveRequestsPage.vue
      PayrollRunsPage.vue
      ...
  talent/
    pages/
      PerformanceCyclesPage.vue
      RecruitmentRequestsPage.vue
      TrainingProgramsPage.vue
      ...
  platform/
    pages/
      SystemUsersPage.vue
      SystemRolesPage.vue
      SystemMenusPage.vue
      ...
```

硬约束：

1. **一页一文件**：每个 `page_code` 必须映射到一个唯一 `.vue` 文件；禁止 `defineComponent(() => h(SharedWorkspace, { key }))` 作为页面实现。
2. **一模块一目录**：员工/部门/合同分属 `people` 模块下的不同 `pages/` 文件与 `api/` 切片；不得把合同表单塞进 `PeopleWorkspace`。
3. **共享只允许“无业务分支”的组件**：`DataTable`、`PageHeader`、`StatusBadge`、`MoneyText` 可共享；带 `mode`/`componentKey` 大分支的“工作区壳”不得作为页面。
4. **表单/详情可复用，但页面必须独立**：例如 `ContractForm.vue` 可被合同录入、续签、变更三个页面复用，但三个页面各有自己的路由、标题、权限、加载与错误边界。
5. **页面注册表由代码生成/校验**：`registry.ts` 的 `componentKey` 必须与模块 `pages/index.ts` 的导出一一对应；CI 校验“无重复组件、无缺失文件、无壳兜底”。

迁移策略：先建目标目录与注册表契约，再按域迁移；迁移期间允许旧壳存在，但注册表不得把旧壳标记为“成熟”。每个域完成后删除该域的壳分支，最终删除四个壳。

### 4.4 导航契约

导航组件必须显式建模三种状态：

| 状态 | 视觉 | 可访问性 | 测试 |
|---|---|---|---|
| 可展开/已收起 | 右侧 ChevronRight（或 Plus） | `aria-expanded="false"`、`aria-controls` | 断言存在展开图标与 `aria-expanded=false` |
| 可展开/已展开 | 右侧 ChevronDown（或 Minus），旋转过渡 | `aria-expanded="true"` | 点击后断言图标方向与 `aria-expanded=true` |
| 叶子项 | 左侧模块/页面图标（可选）+ 文本 | `aria-current="page"` | 当前路由断言高亮与 `aria-current` |

实现要点：

- `navigation.ts` 为父项与子项都保留 `icon` 字段；父项 icon 表示模块，展开指示单独一个 `nav-link__chevron`。
- `router/index.ts` 写入 `meta.navKey = page.name`；`AppShell` 用 `route.meta.navKey` 计算 `currentNavKey`，使当前分组自动展开、子项高亮。
- 折叠侧栏时，父项显示模块图标 + tooltip；展开指示在折叠态可隐藏，但必须保留 `aria-expanded`。
- 菜单状态（哪些分组展开）按用户持久化到 `localStorage`（仅 UI 偏好，不含权限信息），刷新后恢复；当前路由所在分组强制展开。
- 父项点击只切换展开，不直接导航；若希望父项可导航，提供独立“进入概览”子项，避免“点击父项既跳转又展开”的歧义。

### 4.5 单公司上下文（删除法人/租户）

依据 **ADR-0034**：本系统只服务一个公司，不存在法人、租户、公司切换器。

目标：

1. `/v1/me` 不再返回 `tenant_id`、`legal_entities`、`active_legal_entity_id`；只返回用户、角色、`auth_version`、公司配置（名称、时区、货币）。
2. 前端不保存、不发送 `X-Legal-Entity-Id`；删除 `frontend/src/api/entityContext.ts` 与 `activeLegalEntityId`。
3. 删除顶部公司/法人切换器；公司名来自 `company_profile` 单例。
4. `authority_bindings` 使用 `COMPANY` 锚点（`anchor_id=null`、`anchor_key=0`）；数据范围由 `ORG_UNIT`/`ORG_SUBTREE`/`SELF`/`MANAGED`/`CASE`/`PAYROLL_RUN` 表达。
5. 业务日期、时区、货币从 `company_profile` 读取；不再按法人读取。

### 4.6 错误与降级语义

`/me/capabilities` 或 `/me/permissions` 失败时：

- 401 → 清理会话，回登录；
- 403 → 显示“当前账号没有该页面/动作权限”，并给出页面、动作能力、账号、request_id、申请授权入口；不得显示成空白菜单；
- 5xx/网络 → 显示“权限信息加载失败，请重试”，保留已加载的壳与重试按钮；不得把能力置空后继续渲染；
- 契约解析失败 → 进入页面级错误边界并上报 request_id，不得静默吞掉。

---

## 5. 三类缺陷的逐项闭合

### 5.1 缺陷 1：多板块共用页面

**目标：** 160 个 `page_code` 各自对应一个独立页面组件；员工、部门、合同等不同板块在目录、路由、测试、权限上均可区分。

**动作：**

1. 建立 `frontend/src/modules/<domain>/pages/` 目录与 `pages/index.ts` 映射。
2. 按域拆分四个壳（数字以注册表/后端页面目录为准，拆分时逐项核对）：
   - People：员工 11 页、部门 6 页、岗位 6 页、异动 9 页、合同 7 页，共 39 页；其中 37 页当前经壳，2 页已由 `BASE_VIEWS` 独立导入；
   - TimePay：考勤 10 页、请假 8 页、加班 6 页、出差 7 页、薪资 12 页，共 43 页；其中 39 页当前经壳，4 页已独立；
   - Talent：绩效 10 页、招聘需求 7 页、候选人 10 页、培训 9 页，共 36 页；当前全部经壳；
   - Platform：仪表盘 6 页、报表 9 页、公告 7 页、系统 14 页，共 36 页；其中 32 页当前经壳，4 页已独立。
3. 每个页面保留自己的：
   - 页面标题/面包屑/描述；
   - 读接口与五态（loading/empty/error/forbidden/partial）；
   - 写操作按钮与能力门；
   - 组件测试（真实 payload）；
   - 真实栈 E2E 路径。
   每个 `page_code` 必须同时出现在：注册表、模块 `pages/index.ts`、后端 `PermissionResourceCatalog`、`role_pages` 种子、真实栈 E2E 清单；五处缺一即失败。
4. 删除 `PeopleWorkspace`、`TimePayrollPage`、`TalentWorkspacePage`、`PlatformWorkspace` 的“页面路由”职责；可复用部分下沉为 `components/` 或 `panels/`，不再接收 `componentKey` 做全局分支。
5. 更新 `pageComponents.ts`：每个 `componentKey` 显式导入自己的页面组件；禁止领域桶再返回共享壳。
6. 更新 `audit_route_coverage.mjs`：
   - 解析 `pages/index.ts` 的真实导出；
   - 校验每个 `componentKey` 对应唯一 `.vue` 文件；
   - 校验没有两个 key 指向同一文件（允许显式白名单，例如 base 页复用 `AccountSecurityView`，但必须注明原因）；
   - 校验 `pageComponents.ts` 的导入路径真实存在；
   - 失败时输出“key → 文件 → 重复组”清单。
7. 更新测试：
   - 组件测试按页面挂载，不再按 key 挂壳；
   - 增加“员工/部门/合同是不同组件”断言；
   - 增加“同域两页的标题、API、权限、表单互不串用”断言；
   - 真实栈 E2E 每页断言其专属 `data-page` 或标题，而不只是 `<h1>` 存在。

**验收：**

- 160 个 `page_code` → 160 个唯一页面组件（base 页允许注明复用）；
- `audit_route_coverage.mjs --require-mature` 对“共享壳”场景必须失败（用变异测试证明）；
- `people`、`organization`、`contracts` 三类页面各自有独立目录与测试；
- 任意修改合同页不会触碰员工页文件；
- 真实栈 160 页审计能区分页面归属。

### 5.2 缺陷 2：超管大量页面 403

**目标：** `demo.admin` 在演示环境所有页面可进入、所有已授权动作可执行；任何 403 都能解释为“缺页面/缺动作/越组织/字段受限”中的一种，而不是黑箱。依据 ADR-0034，系统不再有法人/租户维度。

**动作：**

1. **删除法人/租户上下文**（ADR-0034）：
   - 删除 `legal_entities`、`tenant_id`、`legal_entity_id`、`X-Legal-Entity-Id`、`activeLegalEntityId`；
   - 新增 `company_profile` 单例，承载公司名、时区、货币、国家、工作日历、工资参数；
   - `LEGAL_ENTITY` 锚点迁移为 `COMPANY`（`anchor_id=null`、`anchor_key=0`）；
   - 演示种子补写 `user_roles`（`demo.admin → SYSTEM_ADMIN`、`demo.hr → HR_ADMIN`、`demo.manager → LINE_MANAGER`、`demo.employee → EMPLOYEE`），并让安装器/`iam:bind-role` 保持“角色 + 绑定 + 页面”三写一致；
   - 增加真实栈测试：`demo.admin` 登录 → `/me` 无租户/法人字段 → `/payroll/runs` 等返回 200/空列表而非 403。
2. **修能力加载**：
   - `/me/permissions` 成为前端会话唯一权限投影；
   - 失败不得 `catch { capabilities = [] }`；
   - 增加 `permissionsStatus`（loading/ready/error）与错误页；
   - 增加单元测试：`/me/permissions` 500 时显示错误而不是空菜单；403 时显示页面/动作/账号/request_id。
3. **修前后端能力码漂移**：
   - 以 `PermissionResourceCatalog` 为页面权限真相，前端 `registry.ts` 的页面能力必须与之一致；
   - 增加 CI 对账脚本：逐 `path` 比较 `required_capability`，任何不一致失败；
   - 修正 3 个 travel 页面：让“页面进入能力”与“页面动作能力”分离，例如 `/travel/policies` 页面进入用 `time.travel.read`，页面内“发布政策”按钮用 `time.travel.policy.manage`；后端读接口若要求 `time.travel.policy.read`，则页面进入能力应为 `time.travel.policy.read` 或后端放宽为 `time.travel.read`，二者必须一致并写 ADR/测试。
4. **落地页面授权**：
   - 新迁移 `role_pages`；
   - 模板/自定义角色写入页面集合；
   - `/me/permissions` 先按页面集合过滤，再做能力校验；
   - 前端路由守卫先查页面集合；
   - `SUPER_ADMIN`/`SYSTEM_ADMIN` 显式拥有全部 160 页；
   - 增加“有页面无动作”“有动作无页面”两类负测。
5. **修 `*` 的可解释性**：
   - 管理端展示 `wildcard: true`、展开后的能力数、页面数、锚点（全公司/组织/本人）、有效期；
   - 不再用“★”作为唯一视觉标识；用“通配授权（*）· 全公司 · 2027-11-25 到期 · 已展开 159 能力 / 160 页面”这样的结构化文案；
   - 增加“为什么这个页面不可见”诊断端点或前端诊断面板：缺页面、缺能力、越组织/越本人、字段受限、菜单覆盖隐藏。
6. **修验收**：
   - 真实栈不再使用任何法人/租户请求头或 localStorage；
   - `demo.admin` 必须跑 160 页审计，且断言每页不是 `/forbidden`；
   - `demo.hr`、`demo.employee` 的 403 必须是“预期拒绝”，并在测试中显式列出允许/拒绝矩阵；
   - `capability:audit` 增加前后端页面能力对账。

**验收：**

- `demo.admin` 160/160 页可进入，0 个非预期 403；
- `demo.hr` 在授权页面 100% 可进入，未授权页面 100% 被拦，且提示可解释；
- 页面授权可独立勾选：只授“员工列表”不授“教育经历”时，前者可见、后者 403；
- 有页面无动作时页面可进入但按钮隐藏，API 403；
- 有动作无页面时菜单不显示，直访 URL 403；
- `/travel/*` 三页前后端能力一致；
- 组织范围/本人范围切换后权限与数据同步变化；不存在法人/租户切换。

### 5.3 缺陷 3：菜单无展开图标

**目标：** 用户一眼能看出哪些菜单可展开、当前展开到哪、当前页面在哪。

**动作：**

1. 数据结构：
   - `NavigationItem` 增加 `expandable: boolean`；
   - `NavigationChild` 增加 `icon?: string`；
   - 注册表每个菜单项保留 `icon`（当前 154 个子项全是 `Menu`，需要按页面语义补图标或明确使用统一文档图标）。
2. 渲染：
   - 父项右侧增加 `nav-link__chevron`，展开时 `ChevronDown`，收起时 `ChevronRight`；
   - 子项左侧渲染 `child.icon`（无则用统一 `Document`）；
   - 折叠侧栏时父项显示 tooltip，展开指示可隐藏但 `aria-expanded` 保留；
   - 过渡动画尊重 `prefers-reduced-motion`。
3. 活动态：
   - `router/index.ts` 写 `meta.navKey = page.name`；
   - `AppShell` 用 `currentNavKey` 自动展开当前分组；
   - 子项 `aria-current="page"` 与高亮；
   - 父项在有活动子项时显示 `nav-link--group-active` 状态。
4. 持久化：
   - `expandedGroups` 初始化时读取 `localStorage['hrms.nav.expanded']`；
   - 用户手动展开/收起时写回；
   - 当前路由分组强制展开，不因持久化状态隐藏。
5. 可访问性：
   - 父按钮有 `aria-expanded`、`aria-controls`、可访问名称“展开/收起 <分组名>”；
   - 子项是链接，支持键盘 Enter/Space；
   - 焦点可见环；
   - 增加 axe 检查（serious/critical=0）。
6. 测试：
   - 组件测试：渲染父项存在 chevron；点击后 `aria-expanded` 与图标方向变化；
   - 路由测试：进入 `/hr/departments` 时 `hr/*` 分组自动展开且子项高亮；
   - 持久化测试：刷新后展开状态保留；
   - 折叠态测试：父项 tooltip 存在，子项不渲染；
   - 视觉回归：至少 1440×900 与 390×844 两张截图。

**验收：**

- 所有有子项的菜单都显示展开/收起指示；
- 当前页面所在分组自动展开并高亮；
- 键盘可完成展开/收起/进入子页；
- 刷新后展开偏好保留；
- axe 无 critical/serious 违规；
- 用户无需猜测即可知道哪些菜单可展开。

---

## 6. 备选方案与取舍

| 方案 | 内容 | 优点 | 缺点 | 结论 |
|---|---|---|---|---|
| A. 只修表现 | 给菜单加箭头；给 `demo.admin` 临时补绑定；页面继续共用壳 | 最快看到变化 | 页面边界、页面授权、门禁盲区仍在；下一个板块继续复用壳；403 会以另一种形式复发 | 否 |
| B. 只拆页面 | 把 4 个壳拆成 160 个页面，权限模型不动 | 代码结构改善 | 页面仍按能力隐式可见；无法只授某页；超管仍可能因单公司上下文/加载失败 403 | 否 |
| C. 只重做权限 | 加 `role_pages`、页面授权、单公司上下文，页面仍共用壳 | 授权可解释 | 页面无法独立测试/评审；合同页改动仍影响员工页；V1.1 页面规格仍落不了地 | 否 |
| D. 页面边界 + 页面授权 + 导航契约 + 门禁一起修 | 本方案 | 一次修掉三个根因；门禁能防复发；可分域增量交付 | 工作量大；需要迁移角色数据和页面目录；需要真实栈回归 | **推荐** |
| E. 全面按 V1.1 重写前端 | 新建 `modules/`、重写所有页面和 IAM | 最贴近文档 | 丢掉已验证的 API 适配、表单和真实栈证据；迁移风险高 | 否 |

**推荐 D 的理由：**

- 保留 V1.0 的能力/锚点/字段内核与 159 个能力码，不重写授权引擎；
- 复用现有 160 个 `page_code` 与前后端 1:1 映射，不造第二套页面目录；
- 页面拆分可按域增量进行，每个域独立验收，不需要“大爆炸”上线；
- 门禁与变异测试确保同类缺陷不能再次“绿灯通过”。

---

## 7. 迁移与兼容

### 7.1 单公司破坏性迁移（ADR-0034）

用户已确认系统只服务一个公司，允许破坏性改造。迁移顺序：

1. 新增 `company_profile` 单例（公司名、时区、货币、国家、工作日历策略）。迁移必须显式指定保留的旧 `legal_entity_id`（演示用 DEMO-CN）；存在多个法人/租户时默认拒绝，人工确认并全量备份后才可继续。
2. 把 `authority_bindings` 中 `anchor_type='LEGAL_ENTITY'` 迁移为 `COMPANY`（`anchor_id=NULL`、`anchor_key=0`）。
3. 重建唯一索引，去掉 `tenant_id`/`legal_entity_id`；`employee_no` 改为全公司唯一，`positions.code` 改为全公司唯一；未选中的旧法人数据不得静默合并，必须备份后显式清理或导出。
4. 代码切换到 `COMPANY` 与 `CompanyProfileReader`；删除 `X-Legal-Entity-Id`、`activeLegalEntityId`、公司/法人切换器。
5. 最后删除 `tenant_id`、`legal_entity_id` 列与 `legal_entities` 表。
6. 每一步必须 forward-only 新迁移；不得修改已合并迁移；删除列前先备份并演练回滚。

### 7.2 页面授权数据迁移

1. 新增 `role_pages` 表（forward-only，匿名类迁移）。
2. 回填：
   - `SUPER_ADMIN`/`SYSTEM_ADMIN`：全部 160 条；
   - 其他 12 个模板：按 `RolePageAssignmentCatalog` 现有矩阵回填；
   - 自定义角色：按其能力集合映射到页面（映射规则写入迁移脚本，并在迁移后人工复核）。
3. 保留 `role_capabilities` 与 `authority_bindings` 不变；页面授权只是新增一层，不替换动作授权。
4. 修复历史数据：为已有 `authority_bindings` 但缺 `user_roles` 的演示/安装账号补写角色行（按 `purpose_code` 前缀 `DEMO_`/`CLI_` 推断），并生成修复清单；无法推断的账号标记为 `NEEDS_REVIEW`，不得静默跳过。
5. `auth_version` 迁移：页面授权变更与能力变更一样，必须在同一事务内重放受影响用户快照；改密只吊销会话，不改授权版本语义（沿用现有修复）。

### 7.3 前端迁移

1. 先建 `modules/` 与 `pages/index.ts` 契约，注册表仍指向旧壳也能运行。
2. 按域迁移：People → TimePay → Talent → Platform；每域完成后把该域 `componentKey` 指向新页面。
3. 每域验收通过后删除对应壳分支；全部完成后删除四个壳文件。
4. 保留旧路由重定向（如 `/employees` → `/hr/employees`），避免书签失效；重定向不参与权限判定。

### 7.4 回滚

- 代码回滚：按域开关或 revert 对应 PR；页面注册表保留旧壳映射即可回到上一版。
- 数据回滚：`role_pages` 为新增表，删除不影响能力授权；若已切换页面授权判定，回滚时同时恢复“按能力推导页面”的旧逻辑。
- 单公司迁移回滚：恢复迁移前数据库快照；代码回退到同时支持 `LEGAL_ENTITY` 与 `COMPANY` 的过渡版本。
- 关键：页面拆分、权限改造、单公司迁移分开提交、分开验收，避免一次回滚同时丢页面、权限和数据。

---

## 8. 风险与缓解

| 风险 | 影响 | 缓解 |
|---|---|---|
| 160 页拆分工作量大 | 交付周期拉长 | 按域增量；每域独立验收；优先 People/Contracts 证明模式 |
| 页面授权与能力授权双重判定导致“看起来有页面但按钮全灰” | 用户困惑 | 页面内明确显示“只读：缺少动作能力 X”；管理端提供权限预览 |
| 角色页面回填映射错误 | 部分用户看不到应有页面 | 迁移脚本生成差异报告；上线前用 14 个模板 + 4 个演示账号做矩阵回归 |
| 删除法人/租户导致现有 E2E、种子、OpenAPI 大面积失败 | 回归成本高 | 先建 `company_profile` 与迁移报告，再一次性切换；E2E 全部改为无 `X-Legal-Entity-Id`；按 ADR-0034 回滚演练 |
| 删除共享壳引入回归 | 页面行为变化 | 每域先双跑（旧壳 vs 新页）对比 DOM/API/五态，再切流 |
| 门禁变严导致历史债务集中爆红 | 阻断发布 | 分阶段：先 warn + 报告，页面拆分完成后改 fail；但 `--require-mature` 在最终发布必须 fail |
| 自定义角色页面映射无历史数据 | 无法自动回填 | 先按能力集合映射并标记 `MIGRATED_BY_CAPABILITY`，管理员在角色页复核后清除标记 |

---

## 9. 验收总表

| # | 验收项 | 判定方式 | 通过标准 |
|---|---|---|---|
| 1 | 页面独立性 | 静态分析 + 组件测试 | 160 个 page_code 对应唯一页面组件；无共享壳兜底 |
| 2 | 板块区分 | 目录/测试/评审 | 员工、部门、合同分属不同 `pages/` 文件与测试；互不改动 |
| 3 | 超管全通 | 真实栈 E2E | `demo.admin` 160/160 页可进入，0 非预期 403 |
| 4 | 页面授权独立 | 后端 feature + 前端 E2E | 只授单页时仅该页可见；同能力其他页 403 |
| 5 | 页面/动作分离 | 负测 | 有页面无动作：可进入、按钮隐藏、API 403；有动作无页面：菜单隐藏、URL 403 |
| 6 | 单公司上下文 | 静态扫描 + 真实栈 E2E | `tenant_id`/`legal_entity`/`X-Legal-Entity-Id` 代码为 0；公司配置来自 `company_profile` |
| 7 | 能力失败可见 | 单元 + E2E | 500/403 显示错误与 request_id，不出现空菜单 |
| 8 | 前后端能力对账 | CI 脚本 | 160 条路径的 required_capability 完全一致，0 漂移 |
| 9 | 菜单展开指示 | 组件 + 视觉 + axe | 所有父项有 chevron；展开/收起状态可见；axe 0 critical/serious |
| 10 | 当前路由高亮 | 组件 + E2E | 进入子页后分组自动展开、子项 `aria-current=page` |
| 11 | 门禁防复发 | 变异测试 | 人为把两页指向同一组件，`--require-mature` 必须失败 |
| 12 | 文档一致 | 文档评审 | 本方案、修改计划、ADR、OpenAPI、`docs/02-permissions.md` 同步更新 |

---

## 9A. 用户要求的现状差距（2026-11-25 核对）

| 要求 | 当前状态 | 差距 | 对应任务 |
|---|---|---|---|
| 权限精确到路由 | 已具备：`route.meta.capability` + `/v1` 中间件 + OpenAPI `x-capability`；`capability:audit` 覆盖 308 操作、6 条公开白名单 | 前端页面守卫仍只查 `capability`，未同时查 `pageCode`（Task 7 补）；`meta.navKey` 缺失 | Task 3、7、8 |
| 权限精确到角色 | 部分具备：`user_roles`、`role_capabilities`；角色模板 14 个 | **无 `role_pages`**；角色页只能编辑能力包，不能勾选页面；`RolePageAssignmentCatalog` 是只读矩阵 | Task 3、4、14 |
| 权限精确到接口 | 已具备：每条受保护路由有 `capability:<code>`；OpenAPI 308 操作均有 `x-capability`；`OpenApiSchemaContractTest` 校验一致 | 无重大缺口；需把页面 `requiredApis` 与 OpenAPI 操作做双向对账（Task 8） | Task 8 |
| 权限精确到按钮 | 弱：仅有 10/41 个 Vue 文件出现 `session.can()` 或 `CapabilityGate`；约 220 个 `<el-button>`/`<button>`，没有统一动作清单 | **缺 `PageActionSpec`、缺统一 `Can`/`ActionGate`、缺按钮清单门禁**；部分按钮只靠 API 403 兜底 | Task 9A |
| 前端非必要不共用页面 | 不满足：144/154 业务/平台页面经四个共享壳 | 缺一页一组件门禁与模块边界门禁 | Task 9、9B、10–13 |
| 前后端代码/模块清晰 | 后端较好：`app/Modules/*` + `arch:test` 通过（4 条 non-blocking warning）；前端不足：`views/` 混放页面、表单、面板，无 `modules/` 与 ESLint 边界 | 前端缺模块目录、依赖方向规则、命名检查；后端 `arch:test` 未覆盖迁移目录/前端契约 | Task 9B、10–13 |
| 按同事分配角色 | 部分具备：`POST /v1/users/{id}/roles` 存在；用户页可输入 `role_code` | 用户列表不返回真实角色；`normaliseAdminUser()` 用 `is_privileged ? ['SYSTEM_ADMIN'] : []` 伪造；分配是自由文本，不是角色下拉；无撤销端点 | Task 14A |
| 按角色分配权限 | 部分具备：`PUT /v1/roles/{id}/capabilities`；角色页可输入能力码 | 不能勾选页面；不能一次编辑多个能力；无差异预览；无权限预览 | Task 14、15 |
| 权限预览 | 不存在 | 无 `/v1/users/{id}/permissions-preview`；无拒绝原因 | Task 15 |

**结论（第一轮）：** “接口级”已基本达标；“路由级”和“角色级”是部分达标；“按钮级”“非必要不共用页面”“按同事分配角色（可视化）”“按角色分配页面/动作（可视化）”“权限预览”均未达标，必须按 Task 9A、9B、14A、15 补齐。

2026-11-25 第二轮追加的四项要求，现状如下：

| 要求 | 当前状态 | 差距 | 对应任务 |
|---|---|---|---|
| 权限细化到每个按钮，并可按角色分配 | 未达标：约 220 个按钮，只有 10/41 个 Vue 文件出现 `session.can()`；无 `PageActionSpec`；角色页不能按页面列出按钮 | 需要“页面 → 按钮 → 能力码”矩阵、`Can`/`ActionGate`、`actions:check`、四 Tab 角色授权页 | Task 9A、9D、14 |
| 超管账号有全部权限与原始数据 | 部分达标：`iam:bind-role` 写 `*` + `user_roles`；但 `role_pages` 尚不存在；`iam:seed-demo` 只创建 4 个账号和绑定，组织/员工来自 `people:seed-demo`，`demo:seed --live` 是另一条链；安装器不校验 160 页 | 需要统一基线命令、`iam:verify-superadmin`、安装器/演示链路末端校验 | Task 9C |
| 每模块 CRUD，合同软删除 | 未达标：全后端仅 5 条 DELETE 路由（documents、bindings、delegations、reporting/subscriptions、system/menus）；合同无 `deleted_at`，只有 `TERMINATED/ARCHIVED` 状态；79 个资源型前缀缺 DELETE | 需要 CRUD 策略表、`crud:audit`、合同迁移 + SoftDeletes + DELETE/restore + 能力码 | Task 9E |
| 其余按主流 HR/后台系统落实 | 文档未统一 | 需要一张可执行的默认决策表，避免逐项反复确认 | §10D；Task 1/18 文档同步 |



---

## 10. 页面/按钮/角色/模块四组细化要求的验收条款

以下四项不是“原则同意”，而是必须由代码、测试和门禁证明的验收条件。

### 10.1 权限细粒度精确到路由、角色、接口和按钮

| 层级 | 真相源 | 必须满足 | 验证方式 |
|---|---|---|---|
| 路由 | `route.meta.pageCode` + `route.meta.capability` | 每个页面路由同时声明 `pageCode`（页面授权）与 `capability`（读动作）；未登录、无页面、无动作分别拒绝 | `routerRegistry.spec.ts`；页面守卫负测；真实栈 403 矩阵 |
| 角色 | `user_roles` + `role_pages` + `role_capabilities` | 角色是页面与动作的唯一分配容器；页面与动作可独立勾选；父菜单不隐含子页 | `RolePagesApiTest`；`RoleAdminTest`；单页授予/撤销 E2E |
| 接口 | 路由中间件 `capability:<code>` + OpenAPI `x-capability` | 每条 `/v1` 受保护路由恰好一个能力码，且与 OpenAPI 完全一致；不得只靠前端隐藏 | `capability:audit`；`OpenApiSchemaContractTest`；`GapCompletionCapabilityMatrixTest` |
| 按钮 | 页面动作清单 `PageActionSpec[]` | 每个写/导出/审批按钮声明 `capability`；渲染统一走 `Can`/`ActionGate`；无能力时隐藏或禁用并说明原因；直接调 API 仍 403 | 新增 `action-manifest` 门禁；组件负测；API 负测 |

**新增硬约束：**

1. `PageSpec` 增加 `actions: PageActionSpec[]`，至少覆盖该页所有非 GET 操作：

```ts
export type PageActionSpec = {
  key: string
  label: string
  capability: string
  api: string
  ui: 'primary' | 'secondary' | 'danger' | 'hidden'
}
```

2. 页面按钮只能通过统一组件渲染：

```vue
<Can capability="people.employee.create">
  <el-button @click="openCreate">新增员工</el-button>
</Can>
```

3. `Can` 组件在无能力时不得渲染可点击控件；若需要解释，渲染文本“缺少动作能力 `X`”，不得只禁用后仍可触发。
4. 新增脚本 `scripts/audit_action_manifest.mjs`：
   - 收集所有页面 `actions[]`；
   - 收集所有 `<el-button>` / `<button>` 的 `@click`；
   - 每个写操作按钮必须能关联到一个 `capability`；
   - 每个 `capability` 必须出现在 `CapabilityCatalog`；
   - 每个 `api` 必须出现在 OpenAPI 且中间件能力一致。
5. 权限负测必须覆盖四层：
   - 无页面授权 → 路由 403/菜单隐藏；
   - 有页面无动作 → 页面可进入、按钮隐藏、接口 403；
   - 有动作无页面 → 菜单隐藏、直访 URL 403；
   - 越组织/越本人 → 接口 403 或空集，不能靠前端过滤。

### 10.2 前端非必要不共用页面

| 项 | 要求 |
|---|---|
| 页面 | 一个 `page_code` 对应一个唯一 `.vue` 页面文件；禁止四个工作区壳作为路由目标 |
| 共享范围 | 只允许无业务分支的展示组件共享：`DataTable`、`PageHeader`、`StatusBadge`、`MoneyText`、`Timeline`、`EmptyState`、`ErrorState`、`LoadingState`、`Can` |
| 业务组件 | 表格列、表单字段、筛选器、详情面板按模块放在 `modules/<domain>/components/`；跨模块复用必须走 `shared/` 且在 ADR/目录说明中登记 |
| 页面壳 | 允许 `PeoplePageShell` 这类**只负责标题/五态/重试**的布局壳；不得接收 `componentKey` 后按 key 渲染不同业务 |
| 白名单 | `AccountMfaView`/`AccountSessionsView` 共用 `AccountSecurityView.vue` 是唯一页面级复用，必须在 `audit_page_components.mjs` 中显式登记原因 |
| 门禁 | `audit_page_components.mjs` 对“两个 page_code → 同一 `.vue`”失败；`audit_shared_components.mjs` 对“业务分支 props”失败 |

**“非必要”的判定：**

- 共享组件不得出现 `componentKey`、`mode`、`pageCode` 这类“页面身份” props；
- 共享组件不得包含“按页面类型切换业务逻辑”的 `if/else` 或 `switch`；
- 共享组件可以有 `variant`（如 `compact`/`full`）这类纯视觉差异；
- 跨模块复用必须被至少两个模块实际使用，否则先留在本模块。

### 10.3 前后端代码清晰、模块清晰

| 层 | 目标结构 | 门禁 |
|---|---|---|
| 前端 | `src/app/`（bootstrap/router/guards）、`src/layouts/`、`src/modules/<domain>/{pages,components,api,types.ts}`、`src/shared/{components,composables,api,utils}`、`src/styles/` | ESLint `no-restricted-imports`：模块间只能经 `modules/<domain>/index.ts` 或 `shared/`；禁止 `views/` 跨域引用 |
| 后端 | `app/Modules/<Domain>/{Contracts,Http,Models,Services,Domain,Providers}`；跨模块只依赖 `Contracts` | `php artisan arch:test`；`ModuleOwnershipTest`；`TABLE_OWNERS` |
| 契约 | OpenAPI 3.1 是唯一接口真相；`frontend/src/generated/api.ts` 生成，不手改 | `openapi:lint`、`openapi:check`、`OpenApiSchemaContractTest` |
| 命名 | 页面 `XxxPage.vue`；模块 `pages/index.ts`；后端 `XxxController`/`XxxService`；能力码 `<domain>.<resource>.<action>` | `audit_naming.mjs`；代码评审清单 |
| 依赖方向 | 前端页面 → 本模块 api → shared/http；后端 Http → Service → Domain/Contracts；禁止反向依赖 | ESLint + `arch:test` + `dependency-cruiser`（或等价脚本） |

**新增门禁：**

- `frontend/eslint.config.js` 增加模块边界规则；
- `scripts/audit_frontend_modules.mjs` 检查：
  - `modules/*/pages/*.vue` 不得 import 其他模块的 `pages/`；
  - 页面不得直接 import `axios`、`fetch`；
  - 页面不得直接 import `views/` 下的旧壳；
  - `modules/*/api` 必须经 `shared/api/http`。
- 后端 `arch:test` 从“仅 PHP”扩展到“表归属 + 跨模块 Contracts + 迁移目录”三项；当前 4 条 warning 必须逐条登记或消除。

### 10.4 角色可分配权限：按同事分配角色，按角色分配权限

目标操作链：

```text
同事（用户列表）
  → 选择某个同事
  → 勾选一个或多个角色（有效期、原因、锚点）
  → 保存
  → 角色管理
  → 选择某个角色
  → 勾选页面（page_code）
  → 勾选动作能力（capability）
  → 设置数据范围（anchor）与字段档位（P0–P3）
  → 预览该角色下所有用户的最终权限
  → 保存并即时生效
```

**必须实现：**

1. **按同事分配角色**
   - 用户列表返回每个用户当前有效角色（`roles[]`），不是 `is_privileged ? ['SYSTEM_ADMIN'] : []` 的伪造值；
   - “分配角色”从自由输入 `role_code` 改为角色下拉/多选，数据来自 `GET /v1/roles`；
   - 支持一个同事多个角色；
   - 保存调用 `POST /v1/users/{id}/roles`，写 `user_roles` + `authority_bindings` + 对应 `role_pages` 解析；
   - 列表展示角色、有效期、锚点、状态；
   - 支持撤销角色（新增 `DELETE /v1/users/{id}/roles/{roleCode}`），撤权后下一次请求立即 403。

2. **按角色分配权限**
   - 角色页展示三栏：页面树、动作能力、数据范围/字段档位；
   - 页面树来自 `PermissionResourceCatalog`，父节点只控制展开，子页逐个勾选；
   - 动作能力来自 `CapabilityCatalog`，只读，不允许 UI 新建；
   - 保存 `PUT /v1/roles/{id}/pages` 与 `PUT /v1/roles/{id}/capabilities`；
   - 保存前显示差异预览（新增/移除的页面与能力）和高风险确认；
   - 保存后 `AuthorizationSnapshot::replay()`，下一次请求即时生效；
   - 角色列表显示页面数、动作数、成员数、到期日。

3. **权限预览**
   - `GET /v1/users/{id}/permissions-preview` 返回：
     - 有效角色；
     - 可见页面（page_code + path + menu）；
     - 动作能力（capability + anchor + field_profile）；
     - 被拒绝的页面及原因（缺页面/缺动作/越组织或越本人/菜单隐藏）。
   - 前端“权限预览”页可选择同事，按 17 个菜单分组展示结果；
   - 预览使用与真实请求相同的 `MeController` 解析逻辑，不得另写一套。

**验收：**

- 给同事 A 分配 `HR_SPECIALIST`，A 只看到该角色页面；撤销后下一次请求 403；
- 给角色 R 只勾选“员工列表”，不勾选“教育经历”；A 能看到列表、看不到教育经历，直访 `/hr/employees/1/education` 返回 403；
- 给角色 R 勾选“员工导出”但不勾选“员工列表”；A 菜单不显示员工列表，直访 URL 403，导出接口 403；
- 一个同事多个角色时，页面/动作取并集，数据范围按最宽有效锚点，字段档位取最高但不得超过角色上限；
- 所有分配/撤销/页面变更/能力变更均写审计，含操作人、对象、前后值、request_id。

---

## 10A. 页面/按钮权限矩阵（参照主流 HR + 后台管理系统）

参考对象：V1.1 已引用的 RuoYi-Vue3（菜单/按钮授权）、BambooHR、Zoho People，以及通用后台管理系统的“菜单 → 页面 → 按钮 → 接口 → 数据范围”五级授权习惯。参考的是交互与授权分层，不复制其代码或数据模型。

### 10A.1 五级授权对象

| 级别 | 对象 | 代码真相源 | 数据表/字段 | 前端表现 | 后端强制 |
|---|---|---|---|---|---|
| L1 菜单 | 17 个业务菜单 + 系统管理分组 | `PAGE_REGISTRY.menu.group` | `role_pages`（通过页面的 `menu_group` 聚合） | 左侧菜单是否显示 | `/me/permissions.menus` 仅返回有页面授权的分组 |
| L2 页面/路由 | 160 个 `page_code` | `PAGE_REGISTRY.name` = 后端 `page_code` | `role_pages` | 路由守卫；菜单子项 | 页面守卫 + API 能力中间件 |
| L3 按钮/动作 | 每页 `actions[]` | `PageActionSpec` | `role_capabilities` → `authority_bindings` | `Can` / `ActionGate` | 每个写/导出/审批接口的能力中间件 |
| L4 接口 | 308 个 `/v1` 操作 | OpenAPI `operationId` + `x-capability` | 无独立授权表；由 L3 能力编译 | 不直接暴露 | 路由 `capability:<code>` 中间件 |
| L5 数据/字段 | 行范围 + P0–P3 | `AuthorityBinding.anchor_type/anchor_id/field_profile` | `authority_bindings` | 页面提示当前范围；遮罩 | Query Scope + FieldSet 投影 |

### 10A.2 页面动作矩阵的存储方式

不新增“按钮表”。按钮是页面的代码属性，不是运行时配置：

```ts
// frontend/src/router/registry.ts
export type PageActionSpec = {
  key: string              // employee.create
  label: string            // 新增员工
  capability: string       // people.employee.manage
  api: string              // POST /v1/employees
  ui: 'primary' | 'secondary' | 'danger' | 'hidden'
  confirm?: 'none' | 'danger' | 'sod'
}
```

角色保存的是 `capability` 集合（`role_capabilities`），不是按钮 key。这样：

- 页面新增按钮时只改代码注册表，不需要迁移数据库；
- 同一能力可被多个按钮使用；
- 按钮可见性与 API 授权使用同一个能力码，不会出现“前端显示、后端拒绝”或反之；
- 能力码仍由 `CapabilityCatalog` 代码拥有，UI 不能新建。

### 10A.3 角色授权页交互（对齐主流后台）

角色编辑页固定为四个 Tab：

1. **页面权限**：17 个菜单分组 → 160 个页面树；父节点只控制展开与“全选子页”，不隐含授权；每个页面独立勾选；显示“已选 x/160”。
2. **按钮权限**：选中页面后，列出该页 `actions[]` 的按钮清单；每个按钮绑定一个能力码；默认不勾选；显示“查看/新建/编辑/删除/导出/审批/发布/导入”等动作中文名。
3. **数据权限**：为每个能力选择锚点（本人/下属/本部门/本部门及子部门/指定部门/全公司/工资批次/案例）；只能选择该能力目录允许的锚点。系统无“法人”锚点，`COMPANY` 表示全公司。
4. **字段权限**：P0–P3 档位；身份证、银行卡、工资、联系方式单独显示；P3 导出单独开关。

保存时：

- 先展示差异：“新增 3 个页面、移除 1 个按钮、数据范围从本部门改为本部门及子部门”；
- 高风险（`*`、P3、`iam.binding.manage`、工资审批）要求二次确认；
- 保存 `PUT /v1/roles/{id}/pages` + `PUT /v1/roles/{id}/capabilities`；
- 同一事务写 `role_pages`、`role_capabilities`，并 `AuthorizationSnapshot::replay()`；
- 写审计：操作人、角色、前后值、request_id。

### 10A.4 按钮级门禁规则

1. 页面内所有非 GET 操作按钮必须通过 `Can` 组件或 `ActionGate` 渲染；
2. `Can` 无能力时不渲染按钮；`ActionGate` 的 `disable` 模式只渲染“缺少动作能力 X”的说明，不渲染可点击控件；
3. 按钮的 `capability` 必须等于其后端接口的 `x-capability`；
4. 页面加载时不需要为每个按钮单独请求权限；使用 `/me/permissions.actions` 的内存集合；
5. 若接口返回 403，页面必须提示“缺少动作能力 X”，而不是只显示通用错误；
6. 只读用户看到页面时，不渲染空工具栏；在页面顶部显示“只读”徽标与原因。

### 10A.5 验收矩阵

| 场景 | 页面可见 | 按钮可见 | 直接调 API |
|---|---|---|---|
| 无页面、无动作 | 否（菜单隐藏） | 否 | 403 |
| 有页面、无动作 | 是 | 否（或只读说明） | 403 |
| 有页面、有动作 | 是 | 是 | 允许 |
| 有动作、无页面 | 否（菜单隐藏） | 否 | 403（页面守卫先拦） |
| 有页面、有动作、越组织/越本人 | 是 | 是 | 403（anchor 不匹配） |
| 有页面、有动作、字段不足 | 是 | 是 | 200 但字段遮罩/导出 403 |

---

## 10B. 超管账号的完整原始数据与可重复部署

### 10B.1 目标

`demo.admin` 与生产安装器创建的 `SYSTEM_ADMIN` 必须在**首次部署后即可访问全部 160 页、全部按钮能力、全部数据范围**，不依赖人工补数据。后续新增的页面、能力、角色由代码发布自动同步，人工只需再分配具体同事的角色。

### 10B.2 三层基线

| 层 | 内容 | 写入者 | 幂等性 |
|---|---|---|---|
| 代码目录 | 160 个 `page_code`、159 个能力码、14 个角色模板、每页 `actions[]` | `PermissionResourceCatalog`、`CapabilityCatalog`、`RoleTemplateCatalog`、`PAGE_REGISTRY` | 随代码发布 |
| 部署基线 | `SUPER_ADMIN`/`SYSTEM_ADMIN` 的 160 条 `role_pages`、全部能力 `role_capabilities`、管理员的 `user_roles` + `authority_bindings` | `iam:sync-capabilities` + `iam:bind-role` | `firstOrCreate`/`updateOrCreate` |
| 演示数据 | 组织、员工、合同、假期、工资等合成记录 | `demo:seed --live` 或各模块 `*:seed-demo` | 幂等，按业务键 upsert |

### 10B.3 安装链路（生产）

```text
hrms:install
  → 创建 company_profile 单例 + root org unit
  → iam:sync-capabilities（159 能力 + 14 角色 + 每角色页面）
  → workflow/lifecycle/leave/calendar 参考数据
  → 创建管理员用户
  → iam:bind-role --role=SYSTEM_ADMIN
      → 写 user_roles
      → 写 authority_bindings（wildcard `*` + COMPANY 锚点 + P3）
  → 校验：160/160 pages、全部能力、COMPANY 绑定
```

**硬要求：**

- `hrms:install` 结束时运行 `iam:verify-superadmin --username=<admin>`，失败即安装失败；
- `iam:bind-role` 在同一事务写 `user_roles` 与 `authority_bindings`；缺一不可；
- `SUPER_ADMIN`/`SYSTEM_ADMIN` 的 `role_pages` 必须 160 条；新增页面时 `iam:sync-capabilities` 自动补齐；
- wildcard `*` 绑定 `COMPANY`（`anchor_id=null`、`anchor_key=0`），覆盖整个单公司实例；不存在多法人绑定；
- 管理员无任职时，SELF 页面显示“未绑定员工”空态，不 404；
- 超管可读取 P3 工资字段，但每次读取写审计（ADR-0024）。

### 10B.4 演示链路（开发/验收）

```text
deploy/scripts/docker_up.sh --demo
  → migrate
  → hrms:install --company-name="Demo China Co Ltd" --timezone=Asia/Shanghai --admin-username=demo.admin
  → people:seed-demo
  → talent:seed-demo
  → demo:seed --live（组织/人员/任职/条款/假期/工资）
  → iam:verify-superadmin --username=demo.admin
  → 真实栈 160 页审计（demo.admin）
```

**演示基线必须包含：**

- 1 个 `company_profile`、根组织、2 个部门、2 个岗位；
- 至少 12 个合成员工、任职、合同；
- 至少 1 套假期类型与余额、1 个考勤周期；
- 至少 1 个已计算工资批次与工资条；
- 14 个角色模板 + 4 个演示账号（admin/hr/manager/employee）；
- 每个演示账号都有 `user_roles`，不是只有 `authority_bindings`。

### 10B.5 后续演进而非人工补

- 新增页面：改 `PAGE_REGISTRY` + `PermissionResourceCatalog` → `iam:sync-capabilities` 给 `SUPER_ADMIN`/`SYSTEM_ADMIN` 补 `role_pages`；
- 新增能力：改 `CapabilityCatalog` + `RoleTemplateCatalog` → 同步；
- 删除页面/能力：`capability:audit` 拒绝仍有绑定的删除，必须走迁移/撤权流程；
- 人工只做“给同事分配角色”，不手工插 `role_pages`/`authority_bindings`。

### 10B.6 验收

- 全新空库执行 `hrms:install` 后，`SYSTEM_ADMIN` 登录：160/160 页可进入，0 非预期 403；
- 全新空库执行 `docker_up.sh --demo` 后，`demo.admin` 同样 160/160；
- 删除 `user_roles` 中管理员行后，`iam:verify-superadmin` 必须失败（证明校验有效）；
- 新增一个测试页面后，重跑 `iam:sync-capabilities`，超管自动获得该页；不重跑则校验失败并提示。

---

## 10C. 模块 CRUD 标准与合同软删除

### 10C.1 每模块 CRUD 的定义

“每模块可 CRUD”不等于每张表都能物理删除。按 V1.1 §7.1 与领域语义分为三种删除策略：

| 策略 | 适用 | 语义 | 接口 |
|---|---|---|---|
| S 软删除 | 合同、合同模板、员工附件、公告、字典、角色、用户、岗位、部门 | 行保留，`deleted_at` 非空；默认查询排除；可恢复 | `DELETE` → 设置 `deleted_at`；`POST /{id}/restore` 恢复 |
| A 归档/停用 | 员工、任职、工资批次、假期类型、班次、绩效周期、培训项目 | 状态变为 `ARCHIVED`/`DISABLED`/`CLOSED`；历史仍可查 | `POST /{id}/archive` 或 `PATCH status` |
| I 不可变 | 工资条、审批决定、审计事件、账本分录 | 不删除、不覆盖；更正走反向分录/新版本 | 无 DELETE；`POST /{id}/reverse` 或新批次 |

> 合同是 S 类，但 `employment_contract_versions` 是 I 类：主行软删除，版本行仍 append-only。两者不矛盾。

**硬约束：**

- 合同（`employment_contracts`）必须使用 **S 软删除**：新增 `deleted_at`、`deleted_by`、`delete_reason`；`EmploymentContract` 使用 `SoftDeletes`；默认列表排除已删除；`withTrashed()` 仅供审计/恢复。
- 合同模板（`contract_templates`）同样软删除；已发布版本不得物理删除。
- 员工、任职、工资批次使用 A 归档，不物理删除。
- 工资条、审批决定、审计事件使用 I 不可变。
- 每模块必须提供：列表（分页/筛选）、详情、创建、更新、删除/归档、恢复（S/A 类）、审计；缺一由 `crud:audit` 失败。
- 删除必须校验引用：部门有在岗员工不可删；岗位有在岗人员不可删；合同被工资/审批引用时只能软删除并保留引用。
- 删除/恢复必须写审计，含 before/after、原因、request_id。
- 删除接口必须有独立能力码（`*.delete`/`*.archive`/`*.restore`），不能复用 `*.manage` 的读权限。

### 10C.2 现状差距

| 模块 | Create | Read | Update | Delete | 结论 |
|---|---|---|---|---|---|
| 员工 | 有（POST /employees） | 有 | 有（PATCH profile） | 无（应归档） | 需补 archive/restore |
| 部门 | 有（POST /org-units） | 有 | 有（move；编辑缺） | 无（应归档） | 需补 update/archive |
| 岗位 | 有 | 有 | 有（PATCH） | 无（应停用） | 需补 disable/restore |
| 合同 | 有 | 有 | 有（amend/terminate） | **无软删除** | 需加 `deleted_at` + DELETE + restore |
| 合同模板 | 有 | 有 | 有（publish） | 无（应软删除） | 需补 DELETE |
| 考勤 | 有（补正） | 有 | 有（规则/排班） | 不适用（事实不可删） | 保持 |
| 请假/加班/出差 | 有 | 有 | 有（撤销/审批） | 不适用（申请可撤销） | 保持 |
| 薪资 | 有（批次/项目/方案） | 有 | 有 | 不适用（不可变） | 保持 |
| 绩效 | 有 | 有 | 有 | 无（应归档） | 需补 archive |
| 招聘 | 有 | 有 | 有 | 无（应归档/软删除） | 需补 |
| 培训 | 有 | 有 | 有 | 无（应归档） | 需补 |
| 公告 | 有 | 有 | 有 | 有（archive） | 已具备 |
| 用户/角色 | 有 | 有 | 有 | 用户停用；角色无删除 | 需补角色软删除/停用 |
| 字典/参数 | 有 | 有 | 有 | 字典有 DELETE；参数无 | 需补参数删除/停用 |

### 10C.3 合同软删除的具体设计

```sql
ALTER TABLE employment_contracts
  ADD COLUMN deleted_at TIMESTAMP NULL,
  ADD COLUMN deleted_by BIGINT UNSIGNED NULL,
  ADD COLUMN delete_reason VARCHAR(500) NULL,
  ADD INDEX employment_contracts_deleted_idx (deleted_at);
```

- `EmploymentContract` 使用 `Illuminate\Database\Eloquent\SoftDeletes`；
- `ContractLifecycleService::delete()`：校验合同不属于已发布工资批次/生效审批；写 `deleted_at/deleted_by/delete_reason`；写审计 `people.contract.soft_deleted`；
- `DELETE /v1/employment-contracts/{id}` 能力码 `people.contract.delete`；
- `POST /v1/employment-contracts/{id}/restore` 能力码 `people.contract.restore`；
- 合同台账默认 `whereNull('deleted_at')`；“已删除”筛选使用 `onlyTrashed()`；
- 已删除合同仍可被审计/工资历史引用；不得级联物理删除 `employment_contract_versions`；
- **合同编号唯一约束：默认“软删除后合同号仍不可复用”**，不把 `deleted_at` 加入唯一索引；理由是合同号是法律/审计标识，复用会造成历史歧义。若业务确需复用，必须新建 ADR 并同时改合同编号生成规则、审计与迁移，不在本计划默认范围内。
- **与 append-only 的关系：** 合同主行软删除只表示“从当前业务视图移除”，`employment_contract_versions` 仍 append-only，不删除、不改写；恢复只恢复主行可见性，不重写历史版本。

### 10C.4 CRUD 门禁

新增 `php artisan crud:audit`：

- 读取模块清单与每模块声明的 CRUD 策略；
- 检查路由是否存在：list/detail/create/update/delete-or-archive/restore（S 类）；
- 检查每个写路由有独立能力码；
- 检查 S 类软删除模型使用 `SoftDeletes` 且迁移有 `deleted_at`；
- 检查 A 类归档模型有 `archived_at/archived_by/archive_reason`，且 DELETE 路由执行归档；
- 检查删除/归档/恢复测试存在；
- 失败即阻断发布。

---

### 10C.5 员工 CRUD 的明确验收

用户已确认“员工可以 CRUD”。员工使用 **A 类归档**，不是物理删除：

| 操作 | 接口 | 能力码 | 语义 |
|---|---|---|---|
| Create | `POST /v1/employees` | `people.employee.manage` | 创建人员与任职；生成不可复用员工号 |
| Read | `GET /v1/employees`、`GET /v1/employees/{id}` | `people.employee.read` | 默认排除已归档；`include_archived=1` 需归档能力 |
| Update | `PATCH /v1/employees/{id}` | `people.employee.manage` | 更新主档/联系方式；敏感字段按 P0–P3 投影 |
| Delete/Archive | `DELETE /v1/employees/{id}` | `people.employee.archive` | 写 `archived_at/archived_by/archive_reason`，不删除人员、任职、合同、工资历史 |
| Restore | `POST /v1/employees/{id}/restore` | `people.employee.restore` | 清空归档字段；员工号仍保持原值且不可复用 |

硬约束：

- 归档前校验：无在途审批、无未结算工资批次；否则 `409 EMPLOYEE_HAS_ACTIVE_RECORDS`；
- 同一员工重复归档/恢复必须是幂等操作；
- 归档/恢复写审计，含 before/after、原因、request_id；
- 员工号在归档后仍唯一，禁止复用；
- 员工列表的“归档/恢复”按钮由 `Can`/`ActionGate` 控制；API 403 仍为最终授权真相；
- `crud:audit` 把员工声明为“A 归档”，要求 DELETE/restore 路由、能力码、审计与测试齐全。

验收：

- 创建 → 读取 → 更新 → 归档 → 恢复全链路 E2E 通过；
- 归档后默认列表不可见，但历史合同/工资/审计仍可查；
- 恢复后员工重新可见，员工号不变；
- 无 `people.employee.archive` 的用户看不到归档按钮，直接调 API 403。

---

## 10D. 其余待确认项：按主流 HR/后台系统默认

以下为本次按行业惯例直接落实的默认值，不再逐项等待确认；如与公司制度冲突，在实施前以书面方式覆盖。

| 主题 | 默认决策 | 依据 |
|---|---|---|
| 菜单层级 | 二级菜单（分组 → 页面）；不超过三级 | RuoYi、BambooHR、Zoho 常见 |
| 页面权限粒度 | 页面可独立勾选；父菜单不隐含子页 | V1.1 §4.2；主流后台 |
| 按钮权限 | 按页面列原子动作；默认不勾选 | RuoYi 按钮权限；V1.1 §4.7 |
| 数据范围 | 本人/下属/本部门/本部门及子部门/指定部门/全公司/工资批次/案例；无“法人”锚点 | ADR-0034；V1.1 §4.2 |
| 字段权限 | P0–P3 四档；P3 读取/导出单独审计 | ADR-0024；V1.1 §4.6 |
| 角色数量 | 14 个代码模板 + 可创建例外角色 | 现有 RoleTemplateCatalog |
| 公司/租户 | 单公司、无租户、无法人；只有 `company_profile` 单例 | ADR-0034；本次用户要求 |
| 用户多角色 | 允许；页面/动作取并集；数据范围取最宽但受锚点约束 | 主流 RBAC |
| 角色有效期 | 模板默认 1 年；`SUPER_ADMIN` 最长 30 天；`SYSTEM_ADMIN` 最长 366 天 | ADR-0016/0024；BindRoleCommand |
| 权限生效 | 保存后 `AuthorizationSnapshot::replay()`；下一次请求生效 | ADR-0004；现有实现 |
| 权限预览 | 必须支持按用户预览页面/按钮/数据范围/拒绝原因 | V1.1 §4.7；主流后台“模拟用户” |
| 删除策略 | 合同/模板/附件/公告/字典/角色软删除；员工/任职/工资批次归档；工资条/审批/审计不可变 | V1.1 §7.1；本次用户要求 |
| 合同删除 | 软删除，保留版本与审计；合同号不复用 | 本次用户要求 |
| 超管 | 全部页面 + 全部按钮 + 全部数据范围；部署即具备；P3 读取审计 | ADR-0024；本次用户要求 |
| 演示账号 | `demo.admin`/`demo.hr`/`demo.manager`/`demo.employee`；密码来自环境变量 | 现有约定 |
| 审计保留 | 至少 6 年；工资/合同/授权变更不可篡改 | V1.1 §11；行业合规 |
| 分页 | 默认 20，最大 100 | 现有 Projection 约定 |
| 时间 | UTC 存储，业务日期按 `company_profile.timezone` | AGENTS.md；ADR-0003/0034 |
| 金额 | 整数最小单位；费率 decimal(…,6) 以上 | AGENTS.md |
| 导入 | 先校验后写入；逐行报告；可回滚 | V1.1 §10.2 |
| 导出 | 与页面同权限；P3 单独开关；水印+目的 | V1.1 §10.3；ADR-0024 |
| 前端页面复用 | 非必要不共用；共享组件不得含页面身份 | 本次用户要求 |
| 模块边界 | 后端 `app/Modules/*` + Contracts；前端 `modules/*` + shared | ADR-0001；本次用户要求 |
| API 契约 | OpenAPI 3.1 唯一真相；生成类型不手改 | AGENTS.md；ADR |
| 默认拒绝 | 无页面/无能力/越锚点/旧版本一律拒绝 | V1.1 §1.4；OWASP |
| 高危二次确认 | `*`、P3、工资审批、角色变更、批量导出 | ADR-0024；V1.1 §4.7 |
| 管理员无任职 | SELF 页面显示空态，不 404 | ADR-0024；gap audit 2.2 |
| 菜单图标 | 父项业务图标 + chevron；子项页面图标 | 本次用户要求；V1.1 §03B.4 |

---

## 11. 已确认决策与实施基线

以下为已确认的架构级决策（用户 2026-11-25 裁定）。这些是实施基线，不再逐项等待确认：

1. **页面权限作为独立一层落地。** 新增 `role_pages`，支持只授单页；页面权限独立于动作能力。
2. **演示/安装账号同时写 `user_roles` 与 `authority_bindings`。** “角色行缺失”是种子和安装器的失败条件，不静默跳过。
3. **删除法人/租户概念。** 系统只服务一个公司；删除 `legal_entities`、`tenant_id`、`legal_entity_id`、`X-Legal-Entity-Id`、`activeLegalEntityId`；用 `company_profile` 单例与 `COMPANY` 锚点替代（ADR-0034）。
4. **`/travel/*` 三页采用“页面进入能力 + 页面内动作能力”分工，并让前端页面进入能力与后端读接口一致：**

   | 页面 | 页面进入能力 | 页面内动作能力 |
   |---|---|---|
   | `/travel/policies` | `time.travel.policy.read` | `time.travel.policy.manage` |
   | `/travel/approval` | `time.travel.approve` | `time.travel.approve` |
   | `/travel/expenses` | `time.travel.expense` | `time.travel.expense.manage` |

   同时把 `time.travel.policy.read`、`time.travel.approve`、`time.travel.expense` 登记为“页面进入能力”，并在角色模板中显式签发；若最终选择“页面进入统一用 `time.travel.read`”，则必须同步放宽后端读接口并补跨组织/越本人负测，不能只改前端。

5. **页面拆分按域分批上线。** 顺序 People → TimePay → Talent → Platform；每域独立验收、独立回滚。
6. **最终删除四个共享壳。** 可复用的表格/表单下沉为 `components/`，但不再作为页面路由目标。

**用户 2026-11-25 追加的要求（已写入 §10.1–§10.4、§10A–§10D）：**

7. 权限精确到菜单、页面/路由、按钮、接口、数据范围五级；按钮以 `PageActionSpec` + 门禁证明；
8. 前端非必要不共用页面，共享组件不得携带页面身份或业务分支；
9. 前后端目录、依赖方向、命名与契约由 ESLint/`arch:test`/OpenAPI 门禁保证；
10. 支持“按同事分配角色、按角色分配页面/按钮/范围/字段”，并提供权限预览与撤权即时生效；
11. 超管账号部署即拥有全部 160 页、全部按钮、全部数据范围；提供可重复部署的基线数据；
12. 每模块必须具备 CRUD（或明确的归档/不可变策略），合同使用软删除；
13. 参考 RuoYi/BambooHR/Zoho 等主流系统落实页面/按钮授权交互；
14. 其余未决项按 §10D 的行业默认决策执行，不再逐项等待确认。
15. 系统只服务一个公司：无租户、无法人、无公司切换；允许破坏性改造（ADR-0034）。
16. 员工可完整 CRUD（创建、读取、更新、删除/归档、恢复），删除采用归档而非物理删除。

**§10D 的默认决策与 ADR-0034 视为已同意**；实施按该表执行，不再需要逐项确认。

上述裁决已确认，按修改计划执行；计划文件见：
`docs/superpowers/plans/2026-11-25-page-module-authorization-remediation-plan.md`
