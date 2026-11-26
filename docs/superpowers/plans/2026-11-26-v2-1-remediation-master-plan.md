# FactoryHR V2.1 全量整改 — 总控计划

- **状态：** Approved — 2026-11-26 用户批准
- **日期：** 2026-11-26
- **代码基线：** `3aa719e`
- **设计规格：** `docs/superpowers/specs/2026-11-26-factoryhr-v2-1-full-remediation-design.md`
- **执行方式：** `superpowers:subagent-driven-development`（推荐）或 `superpowers:executing-plans`
- **范围：** 完整 V2.1；四册顺序执行，每册独立闭环，不设“以后再补”的阶段。

---

## 1. 为什么是四册

用户已确认：

1. 做到完整 V2.1，可以分计划，但不可以分阶段；每册必须本阶段做完。
2. 按风险与依赖拆 4 册，顺序执行。
3. 系统尚未上线，不做旧 URL/旧页面码兼容，允许破坏性改造。
4. 单公司 cutover 纳入本次，代码与迁移一次完成，并在测试库演练。

因此四册不是“四个阶段”，而是四个可独立交付、独立验收、独立提交的工作包。前一册退出条件是后一册的输入；后一册不得在前一册未闭环时合并。

---

## 2. 四册索引

| 册 | 计划文件 | 目标 | 主要退出证据 |
|---|---|---|---|
| 第 1 册 | `2026-11-26-v2-1-volume-1-decisions-baseline-contracts.md` | 决策、基线、160/140 处置、页面/动作/权限/API/展示契约、失败门禁 | 基线证据、处置表、契约测试、门禁反例失败清单 |
| 第 2 册 | `2026-11-26-v2-1-volume-2-permissions-company-login.md` | 单公司 cutover、权限闭环、用户/角色/菜单/范围/字段工作台、密码登录 | 迁移演练、备份恢复、五角色矩阵、MFA 关闭 API 证据 |
| 第 3 册 | `2026-11-26-v2-1-volume-3-employee-domains.md` | 员工样板与全部业务域 | 各域真实读写、字段/范围权限、跨域 E2E、性能基线 |
| 第 4 册 | `2026-11-26-v2-1-volume-4-platform-release.md` | 平台收敛、真实栈、性能、备份恢复、发布 | 全量门禁、五角色 UAT、回滚演练、最终 GO/NO-GO |

---

## 3. 跨册冻结接口

以下接口在第 1 册冻结，后续册不得改名或改变语义：

### 3.1 页面模型

```ts
import type { PageSpec as RegistryPageSpec } from '@/router/registry'

export type PageKind = 'workspace' | 'detail' | 'governance' | 'base'
export type PageMenu = { group: string; label: string; order: number; icon: string } | null

/** Extends the real registry contract; it must never drop layer/wave/requiredApis/actions/states/e2e/negativeTests/cluster. */
export type PageSpec = RegistryPageSpec & {
  kind: PageKind
  primaryObject?: string
  detailOf?: string
}

export function validatePageModel(pages: PageSpec[]): { ok: boolean; errors: string[] }
```

### 3.2 动作清单

生成文件：`docs/generated/page-actions.json`（机器契约）与
`frontend/src/generated/pageActions.ts`（前端构建时消费的冻结 TypeScript 模块；二者由同一命令生成）。

```json
{
  "version": 1,
  "pages": {
    "system-users": [
      {
        "key": "system-users.create",
        "pageCode": "system-users",
        "label": "新增用户",
        "capability": "iam.user.manage",
        "api": "POST /v1/users",
        "ui": "primary",
        "confirm": "none",
        "anchorTypes": ["ORG_UNIT", "ORG_SUBTREE", "COMPANY"],
        "fieldProfileMax": "P1_INTERNAL"
      }
    ]
  }
}
```

> 员工建档 `POST /v1/employees/onboard` 是第 3 册新增契约；第 1 册只冻结生成器、输入格式与门禁，不得引用尚未存在的 operation。

命令：`node scripts/generate_action_manifest.mjs`；校验：`node scripts/audit_action_manifest.mjs`。

### 3.3 权限链路

```text
role_pages（页面进入权）
  ∧ page.required_capability（页面能力）
  ∧ action capability grant（动作能力）
  ∧ AuthorityBinding（anchor + 有效期 + 版本）
  ∧ FieldProfile（读/写/导出）
  ∧ 业务状态机
  → 服务端允许或稳定拒绝
```

### 3.4 单公司上下文

- `company_profile` 为单例配置，不是数据隔离边界。
- 授权锚点为 `SELF / MANAGED / ORG_UNIT / ORG_SUBTREE / COMPANY / CASE / PAYROLL_RUN`。
- 禁止运行路径出现 `tenant_id`、`legal_entity_id`、`LEGAL_ENTITY`、`X-Legal-Entity-Id`、`activeLegalEntityId`、`tenantLabel`。

### 3.5 登录姿态

- `POST /v1/auth/login` 只接收账号与密码。
- `IAM_MFA_ENABLED=false` 为生产验收配置。
- MFA/WebAuthn 可保留为关闭能力，但不得出现在主登录流程或成为 API 前提。
- 保留密码哈希、限流、锁定、会话、CSRF、撤权/禁用失效、审计。

---

## 4. 执行顺序与门禁

```text
第 1 册
  → 第 1 册退出条件全部满足
第 2 册
  → 第 2 册退出条件全部满足
第 3 册
  → 第 3 册退出条件全部满足
第 4 册
  → 第 4 册退出条件全部满足
  → 最终 GO/NO-GO
```

**禁止：**

- 跳过第 1 册直接改业务页面。
- 在第 2 册未完成单公司迁移前合并第 3 册业务域。
- 第 3 册未完成真实业务闭环就进入“发布验收”。
- 用 mock、静态映射或文档完成替代真实栈与权限负测。
- 为赶进度把“未执行”写成“通过”。

**允许并行准备：**

- 第 1 册契约冻结后，第 2 册的预检、第 3 册的页面设计可并行准备。
- 但实现合并必须按册顺序；共享契约文件同一时间只有一个写入者。

---

## 5. 每册完成定义

每册必须同时满足：

- [ ] 该册全部 Task 的 checkbox 已按实际执行勾选。
- [ ] 该册退出条件有证据文件与命令输出。
- [ ] 新增/修改的 ADR 已评审并接受。
- [ ] 相关门禁全绿；未执行项标 `NOT RUN` 且有阻断说明。
- [ ] **唯一例外（命名且归属到关闭册）：** 第 1 册退出时，`NoTenantNoLegalEntityTest` 与三个存量页面门禁（`page-model:check`、`ui-primitives:check`、`copy:check`）可以对旧代码失败，但必须有逐条归属并在第 2/3 册退出时清零。除此之外不得带失败退出。
- [ ] 代码评审无未关闭 Critical/Important 项。
- [ ] 文档、OpenAPI、页面清单、权限矩阵与实现一致。
- [ ] 进度记录写入 `.superpowers/sdd/progress.md` 或等价持久账本。
- [ ] 可独立提交/回滚；不依赖下一册的半成品。

---

## 6. 统一验收命令

```bash
# 后端
cd backend
../.tools/php/php.exe artisan test
../.tools/php/php.exe vendor/bin/pint --test
../.tools/php/php.exe vendor/bin/phpstan analyse --no-progress
../.tools/php/php.exe artisan capability:audit
../.tools/php/php.exe artisan arch:test

# 前端与契约
cd ..
pnpm typecheck
pnpm lint
pnpm test:unit
node scripts/audit_page_components.mjs
node scripts/audit_frontend_modules.mjs
node scripts/audit_action_manifest.mjs
node scripts/audit_single_company.mjs
node scripts/audit_page_model.mjs
node scripts/audit_ui_primitives.mjs
node scripts/audit_user_copy.mjs
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
pnpm openapi:check
```

**证据规则：**

- 每条命令记录退出码与关键输出；失败保留原始日志。
- 真实栈、迁移、备份恢复、性能必须保存日志与截图/trace。
- 没有实际输出，不得写“已完成”或“生产就绪”。

---

## 7. 关键风险与控制

| 风险 | 控制 |
|---|---|
| 单公司删列丢历史 | 预检、冲突即停、测试库演练、备份恢复、生产窗口 |
| 权限重排导致入口丢失 | 旧新授权差异、最小授权、五角色负测、管理员保护 |
| 动作清单再次漂移 | 生成器单一真相源 + 双向 CI + 禁止手写 |
| 页面收敛误删功能 | 160 条逐项处置、140 项能力等价比对、用户签认废弃 |
| 巨型壳拆解回归 | 纵向迁移、每域闭环、模块级回滚 |
| 真实栈不稳定 | 固定镜像/种子/账号、就绪检查、失败证据保留 |
| 文档与实现再次分裂 | 每册验收同步 ADR/OpenAPI/清单；不一致即阻断 |

---

## 8. 用户批准与执行约束

用户已于 2026-11-26 批准本计划，并明确：

1. 使用 `superpowers:subagent-driven-development`，从第 1 册 Task 1 开始，按 1→2→3→4 顺序推进。
2. 每个 Task 完成后由独立 reviewer 做规格符合性与代码质量双评审。
3. 每册结束做一次整册评审与证据归档；前一册闭环后才能合并下一册实现。
4. 允许多开 subagent 开展无写入冲突的并行工作，以缩短交付时间；共享契约文件同一时间只有一个写入者。
5. 四册全部完成后再给最终 GO/NO-GO，不在中途宣称生产就绪。
