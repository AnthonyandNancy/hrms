# ADR-0034: Single-Company Instance — Remove Tenant and Legal-Entity Concepts

> This document records a **single company** deployment. A destructive change is explicitly
> permitted by the user for this refactor; it is not a reversible compatibility layer.

- **状态：** Accepted — 2026-11-25（用户裁定）
- **取代：** ADR-0002 中“跨法人 Employment、工号按法人唯一、LEGAL_ENTITY 数据边界”部分；ADR-0003 中“法人时区/法人日历”部分；ADR-0016/0024 中“wildcard 绑定法人”部分
- **关联：** ADR-0004（能力锚点）、ADR-0026（页面注册表）、ADR-0027（页面/角色分配）、ADR-0033（页面授权）
- **复审触发：** 公司出现第二个法律主体/分支机构需要独立核算；需要对外输出多公司 SaaS；需要按城市/地区配置不同法定假期与工资规则

## 背景

用户明确：**本系统只服务一个公司，是人事管理系统，不承担法务、税务、社保合规主体的职责，也不存在“法人”和“租户”概念。**

这与当前实现冲突：

- 迁移中有 14 个文件、54 处 `legal_entity_id`；
- 授权有 `LEGAL_ENTITY` 锚点，`authority_bindings.anchor_type` 用它表达数据范围；
- `/v1/me`、`X-Legal-Entity-Id`、前端 `activeLegalEntityId` 都以法人为上下文；
- 时间日历、工资参数、招聘需求、培训、报表都按法人过滤；
- 安装器、演示种子、权限矩阵都以法人 1/2 为隔离边界。

继续保留这些概念会让 UI、权限、种子和验收都围绕一个业务上不存在的对象打转，造成 `demo.admin` 403、菜单/页面能力漂移、默认值错位。用户已授权**破坏性改造**，因此本 ADR 选择直接移除，而不是做“隐藏法人”的兼容层。

## 决策

### 1. 单公司实例

系统只有一个公司。`company` 是业务配置（名称、时区、货币、工作日历、假期、工资参数），不是数据隔离边界：

```text
instance
  └─ company profile (singleton)
       ├─ organization tree
       ├─ employees / employments / assignments
       ├─ contracts / leave / attendance / payroll
       └─ roles / pages / capabilities / buttons
```

### 2. 删除 `tenant_id`

- 所有业务表删除 `tenant_id` 列与相关唯一索引；
- `Principal` 不再携带 `tenantId`；
- 所有 `where('tenant_id', ...)` 改为无过滤或按组织/本人范围过滤；
- 所有跨租户 404/防御性检查改为“资源不存在/越权”统一处理；
- `tenant_name`、`tenantLabel`、租户切换器全部删除。

### 3. 删除 `legal_entities` 与 `legal_entity_id`

- 删除 `legal_entities` 表；
- 删除所有业务表的 `legal_entity_id`、`legal_entity_name`、`X-Legal-Entity-Id`；
- 删除 `LegalEntity` 模型、`OrganizationFacts::legalEntitiesForTenant()`、`LegalEntityInfo`；
- 公司级配置改为单例 `company_profile`，只能由 `system.settings.manage` 修改；
- 时区、货币、国家从 `company_profile` 读取，不再从“法人”读取。

### 4. 用 `COMPANY` 锚点替代 `LEGAL_ENTITY`

授权锚点仍为 7 类，`COMPANY` 替换 `LEGAL_ENTITY`：

| 旧 | 新 | 语义 |
|---|---|---|
| `SELF` | `SELF` | 本人任职 |
| `MANAGED` | `MANAGED` | 下属任职 |
| `ORG_UNIT` | `ORG_UNIT` | 指定组织 |
| `ORG_SUBTREE` | `ORG_SUBTREE` | 组织子树 |
| `CASE` | `CASE` | 指定审批案例 |
| `PAYROLL_RUN` | `PAYROLL_RUN` | 指定工资批次 |
| `LEGAL_ENTITY` | **`COMPANY`** | 全公司（单例，`anchor_id` 为 null，`anchor_key` 为 0） |

- `COMPANY` 的 `anchor_id` 固定为 `null`，`anchor_key` 固定为 `0`，表示“整个实例”；
- `AuthorityBinding` 的 `anchor_type` 不再接受 `LEGAL_ENTITY`；
- 迁移把现有 `LEGAL_ENTITY` 绑定转换为 `COMPANY`；
- `CapabilityCatalog` 中所有 `LEGAL_ENTITY` 替换为 `COMPANY`；
- 中间件 `capability:code,LEGAL_ENTITY,legal_entity_id` 改为 `capability:code,COMPANY`；
- 负测从“跨法人”改为“跨组织/越本人/越部门”。

### 5. 页面与角色授权不变

`role_pages` 仍是页面可见性唯一数据源；页面、按钮、接口、数据范围的五级模型不变。变化只在 L5：

- 数据范围从“本法人”改为“全公司”；
- `COMPANY` 与 `ORG_UNIT`/`ORG_SUBTREE`/`SELF`/`MANAGED`/`CASE`/`PAYROLL_RUN` 组合；
- 不再有“切换法人”动作。

### 6. 时间与工资

- 业务日期按 `company_profile.timezone` 计算；
- 工作日历、节假日、班次、工资参数按公司单例版本化；
- 删除 `legal_entity_id` 后，唯一索引改为 `code`、`(code, version)` 等等价约束；
- 工资批次不再带 `legal_entity_id`，按公司 + 期间 + 批次号唯一。

### 7. 破坏性改造允许

用户已明确允许破坏性改造。执行原则：

1. 数据迁移优先于代码删除：先把现有 `tenant_id`/`legal_entity_id` 数据合并到单公司；
2. 迁移 forward-only，不修改已合并迁移；用新迁移删除列/表/索引；
3. 旧 API 路径可以重命名；前端与 OpenAPI 同步更新；
4. 不保留“兼容双轨”超过一个 release；最终只保留单公司模型；
5. 每次破坏性迁移必须有回滚演练（数据库快照 + 只读切换）。

## 关联产品决策

- `role_pages` 是独立页面授权层（ADR-0033），与租户/法人无关；唯一键为 `(role_code, page_code)`。
- 员工支持完整 CRUD：创建、读取、更新、归档、恢复；不物理删除，员工号不复用。
- 合同使用 soft delete；合同版本历史仍 append-only。

## 后果

### 正面

- 权限、页面、菜单、种子、测试不再围绕不存在的法人/租户对象；
- `demo.admin` 不再因“绑定法人 2、请求法人 1”产生 403；
- 查询更简单，索引更少，页面不再有公司切换器；
- 与用户认知一致：这是公司内部人事系统，不是集团/SaaS。

### 负面

- 14 个迁移文件、54 处列、授权锚点、时间/工资/招聘/培训/报表都要改；
- 现有演示数据、E2E、种子、OpenAPI、前端契约全部要回归；
- 未来若真的需要多公司，必须重新引入 `company_id` 并新建 ADR，成本高；
- 用户明确接受该风险。

### 不做

- 不做“隐藏法人”的兼容层；
- 不做多租户 SaaS 控制面；
- 不做集团合并报表、跨法人调动、多币种法人结算；
- 不把税务/社保法定规则硬编码进系统；公司如需法定计算，由外部财务系统或后续独立项目承担。

## 验证

1. `rg "tenant_id|legal_entity|LEGAL_ENTITY|X-Legal-Entity-Id" backend/app backend/routes backend/database/seeders frontend/src` 为 0（`backend/database/migrations` 只允许出现用于删除旧列的迁移语句）；
2. `php artisan migrate:fresh --seed` 成功；
3. `php artisan iam:verify-superadmin --username=demo.admin` 输出 `pages=160 capabilities=all wildcard=yes roles>=1`；
4. `demo.admin` 真实栈 160/160 页可进入，0 非预期 403；
5. `/me` 不再返回 `tenant_id`/`legal_entities`/`active_legal_entity_id`；
6. 岗位、员工、合同、请假、工资、报表的列表/详情/导出按 `COMPANY`/组织范围过滤正确；
7. 备份恢复演练通过，数据无丢失。

## 迁移顺序（高层）

```text
1. 新增 company_profile 单例；显式指定保留的旧 legal_entity_id（演示用 DEMO-CN）；多个法人/租户时迁移失败，人工确认并全量备份
2. 新增加权 COMPANY 锚点；把 LEGAL_ENTITY 绑定迁移为 COMPANY
3. 删除唯一约束中的 tenant_id/legal_entity_id，重建等价唯一索引；未选中的旧法人数据不得静默合并
4. 代码切换到 COMPANY/单公司上下文
5. 删除 X-Legal-Entity-Id、tenant_id、legal_entity_id 列与 legal_entities 表
6. 删除旧模型/Contracts/前端上下文
7. 全量测试 + 真实栈 + 备份恢复
```

> 注意：第 3 步与第 5 步之间只允许一个 release 的过渡期；期间禁止新增依赖 `tenant_id`/`legal_entity_id` 的代码。
