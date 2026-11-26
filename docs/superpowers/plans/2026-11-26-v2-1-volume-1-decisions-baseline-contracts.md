# FactoryHR V2.1 整改第 1 册：决策、基线与契约 — 详细实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在不改业务行为的前提下，统一冲突规范、复现真实基线、完成 160 条路由与 140 项能力的最终归属，并冻结页面、动作、权限、API 与展示契约，使后续三册可以按接口施工。

**Architecture:** 先以 `company_profile` 单例、密码登录、任务型页面三项裁决修订文档与 ADR；再用现有门禁和真实栈脚本建立可复现基线；接着把 160 条注册项映射为页面/动作/视图/长流程/废弃，并生成机器可读动作清单；最后先写会失败的产品级门禁测试，再实现最小契约与共享骨架。

**Tech Stack:** Laravel 11 / PHP 8.4 / MySQL 8.4 / Vue 3.5 / TypeScript 5.9 / Element Plus 2.14 / Vitest / Playwright / OpenAPI 3.1 / Node.js test runner。

## Global Constraints

- 规范优先级：用户裁决 > `04 专项补充书` > `01/02 V2.1` > `03 路由矩阵` > V1.1/历史 ADR。
- 系统尚未上线：不做旧 URL/旧页面码永久兼容；允许破坏性重构。
- 页面可见性唯一来源是 `role_pages` 与页面所需能力的交集；动作权限必须独立授予。
- 每个 `/v1` 路由必须有 capability 守卫，公开白名单仅限明确条目。
- 金额使用整数最小货币单位；费率使用至少 1e6 缩放的十进制字符串；PHP 禁止浮点金额。
- 时间 UTC 存储；业务日期按 `company_profile.timezone` 与共享日历计算。
- 迁移 forward-only；不修改已合并迁移；破坏性迁移必须备份、演练、恢复校验。
- 用户界面禁止英文枚举、接口路径、表名、字段档位、`Wave`、`TODO`、演示/缺口文案。
- 未执行的检查必须标注 `NOT RUN`；未通过不得声称通过。
- 测试与种子只用合成数据；禁止提交 `.env`、真实凭据、SQLite 数据库或真实员工数据。

---

## 0. 执行顺序与依赖

```text
Task 1  统一裁决文档与 ADR
Task 2  建立可复现的真实基线与证据目录
Task 3  160 路由 / 140 能力最终处置表
Task 4  页面模型与注册表契约
Task 5  动作清单单一真相源（生成器 + 双向门禁）
Task 6  权限目录、OpenAPI 与页面动作对齐契约
Task 7  展示层契约：页面骨架、表格、表单、状态/字典
Task 8  产品级门禁测试先行（页面模型、文案、原语、任务闭环）
Task 9  契约冻结评审与第 1 册验收
```

依赖关系：

- Task 1 是 Task 3–8 的裁决依据。
- Task 2 的证据决定 Task 3 的现状标注与 Task 8 的失败基线。
- Task 3 完成后才能冻结 Task 4 的页面分类与 Task 5 的动作范围。
- Task 4/5/6 共同构成权限链路；Task 7 独立于 4–6，但 Task 8 依赖 4–7。
- Task 9 必须使用 Task 8 的实际失败/通过输出，不得只做文档评审。

---

### Task 1: 统一裁决文档与 ADR

**Files:**
- Create: `docs/adr/0035-page-view-action-boundaries.md`
- Create: `docs/adr/0036-action-manifest-single-source.md`
- Create: `docs/adr/0037-single-company-cutover.md`
- Create: `docs/adr/0038-password-login-final-posture.md`
- Modify: `docs/adr/0025-intranet-mfa-and-operational-posture.md`
- Modify: `AGENTS.md`
- Modify: `HRMS-V1.1实施书.md` §01、§03A、§03B、§04、§11、§13
- Modify: `docs/README.md`

**Interfaces:**
- Consumes: 用户裁决“完整 V2.1、四册、不做旧版兼容、单公司迁移纳入本次”。
- Produces: 可供后续任务引用的 ADR 编号与唯一规范表述；V1.1 中冲突段落的修订版本。

- [ ] **Step 1: 写 ADR-0035 页面、视图、动作与权限边界**

创建 `docs/adr/0035-page-view-action-boundaries.md`，内容必须包含：

```markdown
# ADR-0035: Page, View, Action and Permission Boundaries

- Status: Proposed
- Date: 2026-11-26
- Related: ADR-0026, ADR-0027, ADR-0033

## Context

V1.1 registers create/detail subviews as pages. People has 11 employee page codes
for one object, and PeopleWorkspace re-introduces cross-module navigation.

## Decision

1. A page is a task workspace, not a field group.
2. Create, edit, archive, restore, import and export are actions, never page codes.
3. Detail subviews are tabs/anchors inside one detail route, not page codes.
4. A detail route has `menu: null` and is entered from its list or a deep link.
5. Page-internal tabs must share the same object, task and data scope.
6. Page permission controls entry; action capability controls state changes.
7. The 160-entry registry is an inventory, not a menu contract.

## Alternatives

- Keep every route and only restyle: rejected; fragmentation and permission growth remain.
- Collapse everything into one long page: rejected; deep links and permissions suffer.
- Keep old URLs for compatibility: rejected; the system is not in production.

## Consequences

- Page codes and menu entries shrink.
- Role page grants must be rewritten once, with a reviewed diff.
- Detail pages own tab state and return context.

## Validation

- `page-model:check` rejects action-like page paths and detail pages with menus.
- Every inventory entry has a disposition in the final mapping table.

## Review trigger

A task genuinely requires a multi-step, resumable workflow route.
```

- [ ] **Step 2: 写 ADR-0036 动作清单单一真相源**

创建 `docs/adr/0036-action-manifest-single-source.md`：

```markdown
# ADR-0036: Action Manifest Is Generated from Capability and OpenAPI

- Status: Proposed
- Date: 2026-11-26
- Related: ADR-0035, ADR-0027

## Decision

1. Backend CapabilityCatalog and OpenAPI are the only action truth sources.
2. `docs/generated/page-actions.json` is generated, never hand-edited.
3. Frontend registry imports the manifest at build time.
4. CI fails when a write API lacks an action, an action lacks an API, or capability differs.
5. Runtime never reads documentation files.

## Validation

- `pnpm actions:generate` produces a deterministic file.
- `pnpm actions:check` fails on each mismatch class in fixture tests.
```

- [ ] **Step 3: 写 ADR-0037 单公司 cutover**

创建 `docs/adr/0037-single-company-cutover.md`：

```markdown
# ADR-0037: Single-Company Cutover Execution

- Status: Proposed
- Date: 2026-11-26
- Supersedes: ADR-0034 migration execution notes only
- Related: ADR-0034

## Decision

1. `company_profile` is a singleton configuration, not a data boundary.
2. `LEGAL_ENTITY` bindings migrate to `COMPANY` with anchor_id null and anchor_key 0.
3. Data conflicts abort the migration; they are never silently merged.
4. Production deletion requires snapshot, rehearsal, restore verification and a window.
5. Runtime code may not reference tenant or legal-entity identifiers after cutover.

## Validation

- `single-company:check` scans app, routes, seeders, frontend/src and generated contracts.
- Migration rehearsal proves forward migration, checksums and restore.
```

- [ ] **Step 4: 写 ADR-0038 密码登录最终姿态**

创建 `docs/adr/0038-password-login-final-posture.md`：

```markdown
# ADR-0038: Password Login Is the Final Posture; MFA Is Optional

- Status: Proposed
- Date: 2026-11-26
- Related: ADR-0018, ADR-0025

## Decision

1. Username plus password is the only required login flow.
2. `IAM_MFA_ENABLED` defaults to false and is the production acceptance setting.
3. MFA middleware must pass through when disabled without bypassing capability checks.
4. Password hashing, rate limiting, lockout, session, CSRF, revocation and audit remain.
5. MFA/WebAuthn code may remain but is hidden from the main login UI.

## Validation

- Password-only login test proves no 202/403 for privileged users.
- Disabled MFA tests cover admin, HR and payroll APIs.
```

- [ ] **Step 5: 修订 ADR-0025 的冲突措辞**

将 `docs/adr/0025-intranet-mfa-and-operational-posture.md` 的决定第 2 条改为：

```markdown
2. **内网生产验收配置为 `IAM_MFA_ENABLED=false`**；MFA 保留为可选能力，
   管理员可在 `/account/security` 启用。安装器默认不生成 TOTP，不要求绑定。
   该条以 ADR-0018 与 ADR-0038 为准。
```

在文件头部 `取代` 行后追加：

```markdown
- **2026-11-26 修订：** 删除“内网生产默认开启 MFA”的表述，改为默认关闭、可选启用。
```

- [ ] **Step 6: 修订 AGENTS.md 第 10 条**

将：

```text
10. **UTC storage, explicit business time.** Timestamps are stored in UTC. Business dates are computed from the legal entity's timezone and calendar through the shared clock/calendar contracts.
```

改为：

```text
10. **UTC storage, explicit business time.** Timestamps are stored in UTC. Business dates are computed from the single company profile's timezone and calendar through the shared clock/calendar contracts.
```

- [ ] **Step 7: 修订 V1.1 实施书的冲突段落**

按 §1.1 的规范优先级，至少修改以下位置并在每处加注“2026-11-26 V2.1 裁决”：

- §01 建设原则中的“预留 company_id / 可扩展集团”改为单公司实例，不预留多公司控制面。
- §03A.5 员工档案前增加说明：新增、教育、经历、附件、变更均为动作/视图，不作为独立侧栏页面。
- §03B.1 登录页删除验证码/MFA 作为前置要求，改为账号密码 + 限流 + 会话 + 审计。
- §04 权限模型保留角色/页面/按钮/数据/字段五层，删除租户/法人选择器。
- §11 安全基线将“高权限强制 MFA”改为“MFA 可选，默认关闭；以网络边界、限流、会话撤销和审计补偿”。
- §13 权限测试矩阵把跨租户/跨法人负测替换为跨部门、越本人、越案例、越工资批次负测。

修改后运行：

```bash
rg -n '强制 MFA|默认开启 MFA|多租户|跨租户|法人编号|company_id 预留' HRMS-V1.1实施书.md docs/adr/0025-intranet-mfa-and-operational-posture.md
```

Expected: 仅剩带“V2.1 裁决/历史对照”说明的条目；没有未加注的强制 MFA 或租户要求。

- [ ] **Step 8: 登记文档状态**

在 `docs/README.md` 的变更提案表追加：

```markdown
| FactoryHR V2.1 全量整改（四册） | `superpowers/specs/2026-11-26-factoryhr-v2-1-full-remediation-design.md` | `superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md` | **待用户确认**；四册顺序执行 |
```

并将旧 IA 草稿标记为：

```markdown
| 信息架构、业务页面统一性与生产化文案（旧） | `superpowers/specs/2026-11-26-ia-presentation-production-readiness-design.md` | `superpowers/plans/2026-11-26-ia-presentation-production-readiness-implementation-plan.md` | **Superseded**；内容并入 V2.1 第 1/3 册 |
```

- [ ] **Step 9: 运行文档一致性检查**

```bash
rg -n 'Status: Proposed|状态：.*Proposed' docs/adr/0035-page-view-action-boundaries.md docs/adr/0036-action-manifest-single-source.md docs/adr/0037-single-company-cutover.md docs/adr/0038-password-login-final-posture.md
rg -n '2026-11-26 V2.1 裁决' HRMS-V1.1实施书.md
```

Expected: 4 个 ADR 均为 Proposed；V1.1 至少 6 处带裁决注记。

- [ ] **Step 10: Commit**

```bash
git add docs/adr/0035-page-view-action-boundaries.md docs/adr/0036-action-manifest-single-source.md docs/adr/0037-single-company-cutover.md docs/adr/0038-password-login-final-posture.md docs/adr/0025-intranet-mfa-and-operational-posture.md AGENTS.md HRMS-V1.1实施书.md docs/README.md
git commit -m "docs(adr): freeze V2.1 page, action, company and login decisions"
```

---

### Task 2: 建立可复现的真实基线与证据目录

**Files:**
- Create: `docs/audit/2026-11-26-v2-baseline-evidence.md`
- Create: `docs/audit/2026-11-26-v2-capability-inventory.md`
- Create: `scripts/collect_v2_baseline.mjs`
- Modify: `package.json`
- Test: `scripts/tests/collect_v2_baseline.test.mjs`

**Interfaces:**
- Consumes: 当前提交 `3aa719e`、现有门禁命令、`.tools/php/php.exe`、Docker Compose。
- Produces: `docs/audit/2026-11-26-v2-baseline-evidence.md` 中的实际命令与输出摘要；后续任务引用的基线事实。

- [ ] **Step 1: 写基线收集脚本的失败测试**

创建 `scripts/tests/collect_v2_baseline.test.mjs`：

```js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildBaselineReport } from '../collect_v2_baseline.mjs'

test('baseline report records command, exit code and output tail', () => {
  const report = buildBaselineReport([
    { name: 'node-version', command: 'node --version', exitCode: 0, stdout: 'v24.19.0\n', stderr: '' },
    { name: 'php-test', command: 'php artisan test', exitCode: 1, stdout: 'FAILED\n', stderr: '1 failure\n' },
  ])

  assert.match(report, /## node-version/)
  assert.match(report, /exit code: 0/)
  assert.match(report, /v24\.19\.0/)
  assert.match(report, /## php-test/)
  assert.match(report, /exit code: 1/)
  assert.match(report, /1 failure/)
  assert.match(report, /NOT RUN/)
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
node --test scripts/tests/collect_v2_baseline.test.mjs
```

Expected: FAIL，错误为 `Cannot find module '../collect_v2_baseline.mjs'`。

- [ ] **Step 3: 实现基线收集器**

创建 `scripts/collect_v2_baseline.mjs`：

```js
#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

export function buildBaselineReport(entries) {
  const lines = [
    '# FactoryHR V2.1 Baseline Evidence',
    '',
    `- Generated: ${new Date().toISOString()}`,
    '',
  ]
  for (const entry of entries) {
    lines.push(`## ${entry.name}`, '', `- command: \`${entry.command}\``, `- exit code: ${entry.exitCode}`, '')
    const output = `${entry.stdout}${entry.stderr}`.trim()
    lines.push('```text', output === '' ? 'NOT RUN' : output.slice(-4000), '```', '')
  }
  return `${lines.join('\n')}\n`
}

export function runCommand(name, command, args, cwd = repoRoot) {
  try {
    const stdout = execFileSync(command, args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
    return { name, command: [command, ...args].join(' '), exitCode: 0, stdout, stderr: '' }
  } catch (error) {
    return {
      name,
      command: [command, ...args].join(' '),
      exitCode: typeof error.status === 'number' ? error.status : 1,
      stdout: String(error.stdout ?? ''),
      stderr: String(error.stderr ?? error.message),
    }
  }
}

function main() {
  const php = process.env.PHP_BINARY ?? resolve(repoRoot, '.tools/php/php.exe')
  const entries = [
    runCommand('git-status', 'git', ['status', '--short', '--branch']),
    runCommand('node-version', 'node', ['--version']),
    runCommand('php-version', php, ['--version']),
    runCommand('backend-test', php, ['artisan', 'test', '--compact'], resolve(repoRoot, 'backend')),
    runCommand('backend-pint', php, ['vendor/bin/pint', '--test'], resolve(repoRoot, 'backend')),
    runCommand('backend-phpstan', php, ['vendor/bin/phpstan', 'analyse', '--no-progress'], resolve(repoRoot, 'backend')),
    runCommand('capability-audit', php, ['artisan', 'capability:audit'], resolve(repoRoot, 'backend')),
    runCommand('arch-test', php, ['artisan', 'arch:test'], resolve(repoRoot, 'backend')),
    runCommand('frontend-typecheck', 'pnpm', ['typecheck']),
    runCommand('frontend-lint', 'pnpm', ['lint']),
    runCommand('frontend-unit', 'pnpm', ['test:unit']),
    runCommand('page-components', 'node', ['scripts/audit_page_components.mjs']),
    runCommand('frontend-modules', 'node', ['scripts/audit_frontend_modules.mjs']),
    runCommand('actions-check', 'node', ['scripts/audit_action_manifest.mjs']),
    runCommand('openapi-lint', 'node', ['deploy/scripts/openapi-lint.mjs']),
  ]

  const out = resolve(repoRoot, 'docs/audit/2026-11-26-v2-baseline-evidence.md')
  mkdirSync(dirname(out), { recursive: true })
  writeFileSync(out, buildBaselineReport(entries))
  console.log(out)
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
```

- [ ] **Step 4: 运行测试确认通过**

Run:

```bash
node --test scripts/tests/collect_v2_baseline.test.mjs
```

Expected: PASS，1 test。

- [ ] **Step 5: 增加 npm 脚本**

在根 `package.json` 的 `scripts` 中追加：

```json
"baseline:collect": "node scripts/collect_v2_baseline.mjs"
```

- [ ] **Step 6: 执行真实基线**

Run:

```bash
pnpm baseline:collect
```

Expected: 生成 `docs/audit/2026-11-26-v2-baseline-evidence.md`；每个命令都有退出码；失败项保留原始输出尾部。

- [ ] **Step 7: 人工补齐基线事实**

在 `docs/audit/2026-11-26-v2-baseline-evidence.md` 末尾追加以下小节，并以实际命令结果填写：

```markdown
## 人工核验

- Git commit: `<git rev-parse HEAD>`
- PHP: `.tools/php/php.exe` 实际版本
- Node/pnpm: 实际版本
- MySQL/Docker: 是否可用，不可用则标 `NOT RUN`
- 当前失败门禁清单：逐条引用上面的命令与错误
- 现有未提交文件：引用 `git status`
```

- [ ] **Step 8: Commit**

```bash
git add scripts/collect_v2_baseline.mjs scripts/tests/collect_v2_baseline.test.mjs package.json docs/audit/2026-11-26-v2-baseline-evidence.md
git commit -m "test(audit): capture reproducible V2.1 baseline evidence"
```

---

### Task 3: 160 路由 / 140 能力最终处置表

**Files:**
- Create: `docs/audit/2026-11-26-v2-route-disposition.md`
- Create: `docs/audit/2026-11-26-v2-capability-equivalence.md`
- Create: `scripts/generate_route_disposition.mjs`
- Test: `scripts/tests/generate_route_disposition.test.mjs`

**Interfaces:**
- Consumes: Task 2 的基线、`registry.ts` 160 条、`PermissionResourceCatalog` 160 条、`HRMS-V1.1实施书.md` 140 项。
- Produces: 每条旧条目的 `disposition`（keep/action/view/flow/deprecate）、`targetPageCode`、`capability`、`owner`、`evidence`。

- [ ] **Step 1: 写处置表生成器的失败测试**

创建 `scripts/tests/generate_route_disposition.test.mjs`：

```js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildDispositionRows } from '../generate_route_disposition.mjs'

test('every registry page has one disposition and a target', () => {
  const rows = buildDispositionRows({
    registry: [
      { name: 'hr-employees', path: '/hr/employees', layer: 'business', capability: 'people.employee.read' },
      { name: 'hr-employees-create', path: '/hr/employees/create', layer: 'business', capability: 'people.employee.read' },
    ],
    decisions: {
      'hr-employees': { disposition: 'keep', targetPageCode: 'employees', owner: 'People', evidence: 'list workspace' },
      'hr-employees-create': { disposition: 'action', targetPageCode: 'employees', owner: 'People', evidence: 'create dialog' },
    },
  })

  assert.equal(rows.length, 2)
  assert.deepEqual(rows.map((row) => row.disposition), ['keep', 'action'])
  assert.equal(rows[1].targetPageCode, 'employees')
})

test('missing decision fails closed', () => {
  assert.throws(
    () => buildDispositionRows({
      registry: [{ name: 'orphan', path: '/orphan', layer: 'business', capability: 'x.y' }],
      decisions: {},
    }),
    /missing disposition/,
  )
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
node --test scripts/tests/generate_route_disposition.test.mjs
```

Expected: FAIL，`Cannot find module '../generate_route_disposition.mjs'`。

- [ ] **Step 3: 实现生成器骨架**

创建 `scripts/generate_route_disposition.mjs`，导出：

```js
export const DISPOSITIONS = new Set(['keep', 'action', 'view', 'flow', 'deprecate'])

export function buildDispositionRows({ registry, decisions }) {
  return registry.map((page) => {
    const decision = decisions[page.name]
    if (decision === undefined) throw new Error(`missing disposition for ${page.name}`)
    if (!DISPOSITIONS.has(decision.disposition)) throw new Error(`invalid disposition for ${page.name}`)
    if (decision.disposition === 'deprecate' && decision.evidence.trim() === '') {
      throw new Error(`deprecate requires user-approved evidence: ${page.name}`)
    }
    return {
      pageCode: page.name,
      path: page.path,
      layer: page.layer,
      capability: page.capability,
      disposition: decision.disposition,
      targetPageCode: decision.targetPageCode,
      owner: decision.owner,
      evidence: decision.evidence,
    }
  })
}
```

- [ ] **Step 4: 运行测试确认通过**

Run:

```bash
node --test scripts/tests/generate_route_disposition.test.mjs
```

Expected: PASS，2 tests。

- [ ] **Step 5: 录入最终裁决**

在 `scripts/generate_route_disposition.mjs` 中增加 `DECISIONS` 常量，逐条录入 160 个 `page.name`。录入规则：

- 主工作台：`keep`，`targetPageCode` 指向收敛后的页面码。
- 新增/编辑/导入/导出：`action`。
- 详情子页/同对象区域：`view`。
- 审批、工资批次、报表设计器等长流程：`flow`。
- 只有用户书面签认的条目才可 `deprecate`，并在 `evidence` 写明签认记录。

**必须完成的重点映射：**

```text
hr-employees              keep    employees
hr-employees-create       action  employees
hr-employees-by-id        keep    employee-detail
hr-employees-by-id-education     view employee-detail
hr-employees-by-id-work-history  view employee-detail
hr-employees-by-id-contacts      view employee-detail
hr-employees-by-id-credentials   view employee-detail
hr-employees-by-id-history       view employee-detail
hr-employees-by-id-files         view employee-detail
hr-employees-by-id-changes       view employee-detail
hr-employees-imports      flow    employees-import
hr-departments            keep    departments
hr-departments-editor     action  departments
hr-positions              keep    positions
hr-positions-editor       action  positions
hr-movements              keep    movements
hr-movements-by-id        keep    movement-detail
hr-contracts              keep    contracts
hr-contracts-editor       action  contracts
system-users              keep    system-users
system-roles              keep    system-roles
```

其余条目按上述五类处置规则逐条录入，每行必须独立写明 `page.name`、`disposition`、`targetPageCode`、`owner` 与 `evidence`；不得留空或跨行复用省略。

- [ ] **Step 6: 生成处置表并校验完整性**

在 `main()` 中读取 `frontend/src/router/registry.ts` 的 160 条、匹配 `DECISIONS`，写出：

- `docs/audit/2026-11-26-v2-route-disposition.md`
- `docs/audit/2026-11-26-v2-capability-equivalence.md`

运行：

```bash
node scripts/generate_route_disposition.mjs
```

Expected: 两张表各有 160 行；每行有 disposition、目标页面、owner、证据；无 `missing disposition`。

- [ ] **Step 7: 校验 140 项能力无丢失**

在 `docs/audit/2026-11-26-v2-capability-equivalence.md` 增加断言段：

```markdown
## 完整性断言

- V1.1 业务能力点：140
- 已映射到 keep/action/view/flow：<实际数量>
- 用户签认废弃：<实际数量，必须为 0 或附签认记录>
- 未映射：<必须为 0>
```

用脚本统计并写入实际数字；若有未映射，先补映射再继续。

- [ ] **Step 8: Commit**

```bash
git add scripts/generate_route_disposition.mjs scripts/tests/generate_route_disposition.test.mjs docs/audit/2026-11-26-v2-route-disposition.md docs/audit/2026-11-26-v2-capability-equivalence.md
git commit -m "docs(audit): map every V1.1 route and capability to a V2.1 target"
```

---

### Task 4: 页面模型与注册表契约

**Files:**
- Create: `frontend/src/shared/navigation/pageModel.ts`
- Modify: `frontend/src/router/registry.ts`
- Modify: `frontend/src/router/navigation.ts`
- Test: `frontend/tests/unit/pageModel.spec.ts`
- Test: `frontend/tests/unit/navigation.spec.ts`

**Interfaces:**
- Consumes: ADR-0035、Task 3 处置表。
- Produces:

```ts
export type PageKind = 'workspace' | 'detail' | 'governance' | 'base'
export type PageMenu = { group: string; label: string; order: number; icon: string } | null

export type PageSpec = {
  path: string
  name: string
  componentKey: string
  capability: string
  kind: PageKind
  menu: PageMenu
  primaryObject?: string
  detailOf?: string
}

export function validatePageModel(pages: PageSpec[]): { ok: boolean; errors: string[] }
```

- [ ] **Step 1: 写失败测试**

创建 `frontend/tests/unit/pageModel.spec.ts`：

```ts
import { describe, expect, it } from 'vitest'
import { validatePageModel, type PageSpec } from '@/shared/navigation/pageModel'

const base: PageSpec = {
  path: '/hr/employees',
  name: 'employees',
  componentKey: 'people/employees/index',
  capability: 'people.employee.read',
  kind: 'workspace',
  menu: { group: '员工档案', label: '员工列表', order: 16, icon: 'User' },
  primaryObject: 'employee',
}

describe('page model', () => {
  it('rejects action-like page paths', () => {
    const result = validatePageModel([
      { ...base, path: '/hr/employees/create', name: 'employees-create', kind: 'workspace' },
    ])
    expect(result.ok).toBe(false)
    expect(result.errors.join('\n')).toContain('action-like path')
  })

  it('requires detail pages to have menu null and a list parent', () => {
    const result = validatePageModel([
      base,
      {
        ...base,
        path: '/hr/employees/:id',
        name: 'employee-detail',
        kind: 'detail',
        menu: { group: '员工档案', label: '员工详情', order: 17, icon: 'User' },
        detailOf: 'employees',
      },
    ])
    expect(result.ok).toBe(false)
    expect(result.errors.join('\n')).toContain('detail page must have menu: null')
  })

  it('accepts a valid detail page', () => {
    const result = validatePageModel([
      base,
      {
        ...base,
        path: '/hr/employees/:id',
        name: 'employee-detail',
        kind: 'detail',
        menu: null,
        detailOf: 'employees',
      },
    ])
    expect(result.ok).toBe(true)
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/pageModel.spec.ts
```

Expected: FAIL，`Cannot find module '@/shared/navigation/pageModel'`。

- [ ] **Step 3: 实现 `pageModel.ts`**

```ts
export type PageKind = 'workspace' | 'detail' | 'governance' | 'base'
export type PageMenu = { group: string; label: string; order: number; icon: string } | null

export type PageSpec = {
  path: string
  name: string
  componentKey: string
  capability: string
  kind: PageKind
  menu: PageMenu
  primaryObject?: string
  detailOf?: string
}

const ACTION_LIKE = /\/(create|new|edit|editor|archive|restore|import|export|approval|renewal)(\/|$)/

export function validatePageModel(pages: PageSpec[]): { ok: boolean; errors: string[] } {
  const errors: string[] = []
  const byName = new Map(pages.map((page) => [page.name, page]))

  for (const page of pages) {
    if (page.kind !== 'detail' && ACTION_LIKE.test(page.path)) {
      errors.push(`${page.name}: action-like path must be an action, not a page`)
    }
    if (page.kind === 'detail') {
      if (page.menu !== null) errors.push(`${page.name}: detail page must have menu: null`)
      if (page.detailOf === undefined) errors.push(`${page.name}: detail page requires detailOf`)
      else if (!byName.has(page.detailOf)) errors.push(`${page.name}: detailOf ${page.detailOf} is unknown`)
    }
    if (page.kind === 'workspace' && page.menu === null) {
      errors.push(`${page.name}: workspace requires a menu entry`)
    }
    if (page.kind === 'base' && page.menu !== null) {
      errors.push(`${page.name}: base page must have menu: null`)
    }
  }

  return { ok: errors.length === 0, errors }
}
```

- [ ] **Step 4: 运行测试确认通过**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/pageModel.spec.ts
```

Expected: PASS，3 tests。

- [ ] **Step 5: 扩展注册表类型并迁移 160 条**

在 `frontend/src/router/registry.ts`：

- 引入并复述 `PageKind`、`PageMenu` 类型。
- 为每条 `PageSpec` 增加 `kind`、`primaryObject`、`detailOf`。
- 员工相关条目按 Task 3 映射改为 `workspace`/`detail`；创建、导入、编辑类条目不再作为页面存在。
- 菜单只保留 workspace/governance；detail/base 的 `menu` 为 `null`。

- [ ] **Step 6: 写导航测试**

创建 `frontend/tests/unit/navigation.spec.ts`：

```ts
import { describe, expect, it } from 'vitest'
import { buildMenu } from '@/router/navigation'
import type { PageSpec } from '@/shared/navigation/pageModel'

const pages: PageSpec[] = [
  { path: '/hr/employees', name: 'employees', componentKey: 'a', capability: 'people.employee.read', kind: 'workspace', menu: { group: '员工档案', label: '员工列表', order: 16, icon: 'User' }, primaryObject: 'employee' },
  { path: '/hr/employees/:id', name: 'employee-detail', componentKey: 'b', capability: 'people.employee.read', kind: 'detail', menu: null, detailOf: 'employees' },
  { path: '/system/roles', name: 'system-roles', componentKey: 'c', capability: 'iam.role.manage', kind: 'governance', menu: { group: '系统管理', label: '角色管理', order: 103, icon: 'Lock' } },
]

describe('navigation', () => {
  it('only exposes menu-bearing pages', () => {
    const menu = buildMenu(pages)
    const labels = menu.flatMap((group) => group.pages.map((page) => page.name))
    expect(labels).toEqual(['employees', 'system-roles'])
  })
})
```

- [ ] **Step 7: 实现/调整 `buildMenu`**

在 `frontend/src/router/navigation.ts` 导出纯函数：

```ts
import type { PageMenu, PageSpec } from '@/shared/navigation/pageModel'

export type MenuGroup = { group: string; label: string; order: number; icon: string; pages: PageSpec[] }

export function buildMenu(pages: PageSpec[]): MenuGroup[] {
  const groups = new Map<string, MenuGroup>()
  for (const page of pages) {
    if (page.menu === null) continue
    const menu: PageMenu = page.menu
    const group = groups.get(menu.group) ?? {
      group: menu.group,
      label: menu.group,
      order: menu.order,
      icon: menu.icon,
      pages: [],
    }
    group.order = Math.min(group.order, menu.order)
    group.pages.push(page)
    groups.set(menu.group, group)
  }
  return [...groups.values()].sort((a, b) => a.order - b.order)
}
```

- [ ] **Step 8: 运行前端定向测试**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/pageModel.spec.ts tests/unit/navigation.spec.ts
```

Expected: PASS，4 tests。

- [ ] **Step 9: Commit**

```bash
git add frontend/src/shared/navigation/pageModel.ts frontend/src/router/registry.ts frontend/src/router/navigation.ts frontend/tests/unit/pageModel.spec.ts frontend/tests/unit/navigation.spec.ts
git commit -m "feat(ui): add typed page model and menu-only navigation"
```

---

### Task 5: 动作清单单一真相源

**Files:**
- Create: `scripts/generate_action_manifest.mjs`
- Create: `scripts/tests/generate_action_manifest.test.mjs`
- Create: `docs/generated/page-actions.json`
- Modify: `package.json`
- Modify: `frontend/src/router/registry.ts`
- Test: `frontend/tests/unit/actionManifest.spec.ts`

**Interfaces:**
- Consumes: Task 4 `PageSpec`、`CapabilityCatalog.php`、`docs/openapi/openapi.yaml`。
- Produces:

```json
{
  "version": 1,
  "pages": {
    "employees": [
      {
        "key": "employees.create",
        "pageCode": "employees",
        "label": "新增员工",
        "capability": "people.employee.manage",
        "api": "POST /v1/employees/onboard",
        "ui": "primary",
        "confirm": "none",
        "anchorTypes": ["COMPANY", "ORG_UNIT", "ORG_SUBTREE"],
        "fieldProfileMax": "P2_SENSITIVE"
      }
    ]
  }
}
```

- [ ] **Step 1: 写失败测试**

创建 `scripts/tests/generate_action_manifest.test.mjs`：

```js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildActionManifest, validateActionManifest } from '../generate_action_manifest.mjs'

test('generates deterministic actions from registry, catalog and OpenAPI', () => {
  const manifest = buildActionManifest({
    pages: [{ name: 'employees', path: '/hr/employees', requiredApis: ['POST /v1/employees/onboard'] }],
    catalog: [{ code: 'people.employee.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P2_SENSITIVE' }],
    openapi: { paths: { '/v1/employees/onboard': { post: { 'x-capability': 'people.employee.manage', 'x-action-label': '新增员工' } } } },
    actions: [{ pageCode: 'employees', key: 'employees.create', label: '新增员工', capability: 'people.employee.manage', api: 'POST /v1/employees/onboard', ui: 'primary', confirm: 'none' }],
  })
  assert.deepEqual(manifest.pages.employees.map((action) => action.key), ['employees.create'])
  assert.equal(manifest.pages.employees[0].fieldProfileMax, 'P2_SENSITIVE')
})

test('rejects action capability that differs from OpenAPI', () => {
  assert.throws(
    () => buildActionManifest({
      pages: [{ name: 'employees', path: '/hr/employees', requiredApis: ['POST /v1/employees/onboard'] }],
      catalog: [{ code: 'people.employee.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P2_SENSITIVE' }],
      openapi: { paths: { '/v1/employees/onboard': { post: { 'x-capability': 'people.employee.create' } } } },
      actions: [{ pageCode: 'employees', key: 'employees.create', label: '新增员工', capability: 'people.employee.manage', api: 'POST /v1/employees/onboard', ui: 'primary', confirm: 'none' }],
    }),
    /capability mismatch/,
  )
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
node --test scripts/tests/generate_action_manifest.test.mjs
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 实现清单生成与校验**

在 `scripts/generate_action_manifest.mjs` 中实现：

```js
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import YAML from 'yaml'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

export function buildActionManifest({ pages, catalog, openapi, actions }) {
  const pageNames = new Set(pages.map((page) => page.name))
  const capabilities = new Map(catalog.map((entry) => [entry.code, entry]))
  const result = { version: 1, pages: {} }

  for (const action of actions) {
    if (!pageNames.has(action.pageCode)) throw new Error(`unknown page ${action.pageCode}`)
    const capability = capabilities.get(action.capability)
    if (capability === undefined) throw new Error(`unknown capability ${action.capability}`)
    const [method, path] = action.api.split(' ', 2)
    const operation = openapi.paths?.[path]?.[method.toLowerCase()]
    if (operation === undefined) throw new Error(`missing OpenAPI operation ${action.api}`)
    if (operation['x-capability'] !== action.capability) {
      throw new Error(`capability mismatch for ${action.key}: ${operation['x-capability']} != ${action.capability}`)
    }
    result.pages[action.pageCode] ??= []
    result.pages[action.pageCode].push({
      ...action,
      anchorTypes: capability.anchorTypes,
      fieldProfileMax: capability.fieldProfileMax,
    })
  }

  for (const actionList of Object.values(result.pages)) {
    actionList.sort((a, b) => a.key.localeCompare(b.key))
  }
  return result
}

export function validateActionManifest({ pages, manifest, openapi }) {
  const errors = []
  for (const page of pages) {
    const declared = new Set((manifest.pages[page.name] ?? []).map((action) => action.api))
    for (const api of page.requiredApis.filter((item) => /^(POST|PUT|PATCH|DELETE) /.test(item))) {
      if (!declared.has(api)) errors.push(`${page.name}: write API ${api} has no action`)
    }
  }
  for (const [pageCode, actions] of Object.entries(manifest.pages)) {
    for (const action of actions) {
      const [method, path] = action.api.split(' ', 2)
      if (!openapi.paths?.[path]?.[method.toLowerCase()]) errors.push(`${pageCode}/${action.key}: missing API ${action.api}`)
    }
  }
  return { ok: errors.length === 0, errors }
}
```

- [ ] **Step 4: 运行测试确认通过**

Run:

```bash
node --test scripts/tests/generate_action_manifest.test.mjs
```

Expected: PASS，2 tests。

- [ ] **Step 5: 提供手工动作输入文件**

创建 `docs/generated/action-input.json`，至少包含：

```json
{
  "actions": [
    {
      "pageCode": "employees",
      "key": "employees.create",
      "label": "新增员工",
      "capability": "people.employee.manage",
      "api": "POST /v1/employees/onboard",
      "ui": "primary",
      "confirm": "none"
    },
    {
      "pageCode": "employees",
      "key": "employees.archive",
      "label": "归档员工",
      "capability": "people.employee.archive",
      "api": "POST /v1/employees/{id}/archive",
      "ui": "danger",
      "confirm": "danger"
    }
  ]
}
```

其余动作在第 2 册 IAM-01 完成后补齐；本任务只冻结输入格式与生成器。

- [ ] **Step 6: 生成并检查确定性**

在 `package.json` 增加：

```json
"actions:generate": "node scripts/generate_action_manifest.mjs",
"actions:check": "node scripts/audit_action_manifest.mjs"
```

运行两次生成并比较：

```bash
node scripts/generate_action_manifest.mjs
sha256sum docs/generated/page-actions.json
node scripts/generate_action_manifest.mjs
sha256sum docs/generated/page-actions.json
```

Expected: 两次 SHA-256 相同。

- [ ] **Step 7: 前端消费清单**

在 `frontend/src/router/registry.ts` 中删除手写 `PageActionSpec[]` 数据，改为：

```ts
import actionManifest from '../../../docs/generated/page-actions.json'

export function actionsForPage(pageCode: string) {
  return actionManifest.pages[pageCode] ?? []
}
```

并在 `frontend/tests/unit/actionManifest.spec.ts` 断言：

```ts
import { describe, expect, it } from 'vitest'
import manifest from '../../../docs/generated/page-actions.json'

describe('action manifest', () => {
  it('contains only actions with api and capability', () => {
    for (const actions of Object.values(manifest.pages)) {
      for (const action of actions) {
        expect(action.api).toMatch(/^(POST|PUT|PATCH|DELETE) \/v1\//)
        expect(action.capability).toMatch(/^[a-z][a-z0-9]*(\.[a-z0-9-]+)+$/)
      }
    }
  })
})
```

- [ ] **Step 8: 运行前端测试**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/actionManifest.spec.ts
```

Expected: PASS，1 test。

- [ ] **Step 9: Commit**

```bash
git add scripts/generate_action_manifest.mjs scripts/tests/generate_action_manifest.test.mjs docs/generated/action-input.json docs/generated/page-actions.json package.json frontend/src/router/registry.ts frontend/tests/unit/actionManifest.spec.ts
git commit -m "feat(contract): generate page actions from capability and OpenAPI"
```

---

### Task 6: 权限目录、OpenAPI 与页面动作对齐契约

**Files:**
- Modify: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php`
- Modify: `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`
- Modify: `docs/openapi/openapi.yaml`
- Modify: `backend/tests/Contract/PagePermissionCoverageTest.php`
- Create: `backend/tests/Contract/ActionManifestParityTest.php`

**Interfaces:**
- Consumes: Task 5 `docs/generated/page-actions.json`。
- Produces: 后端目录、OpenAPI、生成清单三方一致的契约测试。

- [ ] **Step 1: 写失败测试**

创建 `backend/tests/Contract/ActionManifestParityTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use PHPUnit\Framework\TestCase;

final class ActionManifestParityTest extends TestCase
{
    public function test_generated_manifest_matches_backend_catalog_and_pages(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__.'/../../../docs/generated/page-actions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $pages = (new PermissionResourceCatalog)->pages();
        $capabilities = new CapabilityCatalog;

        foreach ($manifest['pages'] as $pageCode => $actions) {
            $page = (new PermissionResourceCatalog)->find($pageCode);
            $this->assertNotNull($page, "Unknown page {$pageCode} in manifest.");
            foreach ($actions as $action) {
                $this->assertTrue($capabilities->has($action['capability']), "Unknown capability {$action['capability']}.");
                $this->assertContains($action['capability'], $page['actions'], "{$pageCode} does not declare {$action['capability']}.");
                $this->assertContains($action['api'], $page['required_apis'], "{$pageCode} does not declare {$action['api']}.");
            }
        }
    }
}
```

- [ ] **Step 2: 运行测试确认失败或暴露真实缺口**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter=ActionManifestParityTest
```

Expected: 若清单尚不完整则 FAIL，并列出具体页面/能力；记录输出到证据文件。

- [ ] **Step 3: 补齐页面资源目录的 actions**

在 `PermissionResourceCatalog.php` 中为每个可写页面补齐真实动作能力码；不得再用 `required_capability` 冒充按钮动作。重点页面：

```php
// employees
'actions' => ['people.employee.manage', 'people.employee.archive', 'people.employee.restore', 'people.employee.export'],
// system-users
'actions' => ['iam.user.manage', 'iam.role.manage'],
// system-roles
'actions' => ['iam.role.manage', 'iam.scope.manage', 'iam.field.manage'],
// payroll run workspace
'actions' => ['payroll.run.read', 'payroll.run.prepare', 'payroll.run.review', 'payroll.run.publish', 'payroll.run.export'],
```

能力码必须已存在于 `CapabilityCatalog`；缺失时先在目录中新增并说明语义、anchor 类型与最高字段档位。

- [ ] **Step 4: 同步 OpenAPI**

为每个动作对应的写操作补齐：

```yaml
x-capability: people.employee.manage
x-action-label: 新增员工
```

运行：

```bash
cd backend && ../.tools/php/php.exe artisan capability:audit
node deploy/scripts/openapi-lint.mjs
```

Expected: capability audit 无新增违规；OpenAPI lint 通过。

- [ ] **Step 5: 扩展页面覆盖契约测试**

在 `PagePermissionCoverageTest.php` 增加：

```php
public function test_every_declared_action_capability_exists_and_is_not_just_read_capability(): void
{
    $catalog = new CapabilityCatalog;
    foreach ((new PermissionResourceCatalog)->pages() as $page) {
        foreach ($page['actions'] as $action) {
            $this->assertTrue($catalog->has($action), "{$page['page_code']} declares unknown action {$action}.");
            if ($action !== $page['required_capability']) {
                $this->assertStringNotContainsString('.read', $action, "{$page['page_code']} uses a read code as a write action.");
            }
        }
    }
}
```

- [ ] **Step 6: 运行契约测试**

Run:

```bash
cd backend && ../.tools/php/php.exe artisan test --compact --filter='ActionManifestParityTest|PagePermissionCoverageTest'
```

Expected: PASS；若仍失败，按输出逐页补齐，不得放宽断言。

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/IAM/Authorization/CapabilityCatalog.php backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php docs/openapi/openapi.yaml backend/tests/Contract/PagePermissionCoverageTest.php backend/tests/Contract/ActionManifestParityTest.php docs/generated/page-actions.json
git commit -m "feat(iam): align page actions, capabilities and OpenAPI"
```

---

### Task 7: 展示层契约与共享骨架

**Files:**
- Create: `frontend/src/shared/ui/PageShell.vue`
- Create: `frontend/src/shared/ui/FormDialog.vue`
- Create: `frontend/src/shared/ui/FormDrawer.vue`
- Create: `frontend/src/shared/display/dictionary.ts`
- Modify: `frontend/src/components/DataTable.vue`
- Modify: `frontend/src/utils/status.ts`
- Test: `frontend/tests/unit/dictionary.spec.ts`
- Test: `frontend/tests/unit/PageShell.spec.ts`

**Interfaces:**
- Consumes: 现有 `DataTable.vue`、`PageHeader.vue`、`ErrorState.vue`、`EmptyState.vue`、`StatusBadge.vue`。
- Produces:

```ts
export type DictionaryEntry = { label: string; tone?: 'success' | 'warning' | 'danger' | 'info' }
export function translate(domain: string, field: string, code: string | null | undefined): DictionaryEntry
```

- [ ] **Step 1: 写字典失败测试**

创建 `frontend/tests/unit/dictionary.spec.ts`：

```ts
import { describe, expect, it } from 'vitest'
import { translate } from '@/shared/display/dictionary'

describe('dictionary', () => {
  it('translates employee status', () => {
    expect(translate('people', 'employeeStatus', 'ACTIVE').label).toBe('在职')
    expect(translate('people', 'employeeStatus', 'PROBATION').label).toBe('试用')
  })

  it('translates employment type and payroll unit', () => {
    expect(translate('people', 'employmentType', 'FULL_TIME').label).toBe('全职')
    expect(translate('payroll', 'amountUnit', 'MINOR_UNITS').label).toBe('最小货币单位')
  })

  it('returns a safe placeholder and records unknown codes', () => {
    expect(translate('people', 'employeeStatus', 'UNKNOWN_CODE').label).toBe('未知')
  })
})
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/dictionary.spec.ts
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 实现字典**

创建 `frontend/src/shared/display/dictionary.ts`：

```ts
export type DictionaryEntry = { label: string; tone?: 'success' | 'warning' | 'danger' | 'info' }

const DICTIONARY: Record<string, Record<string, DictionaryEntry>> = {
  'people.employeeStatus': {
    ACTIVE: { label: '在职', tone: 'success' },
    PROBATION: { label: '试用', tone: 'warning' },
    TERMINATED: { label: '离职', tone: 'info' },
    ARCHIVED: { label: '已归档', tone: 'info' },
  },
  'people.employmentType': {
    FULL_TIME: { label: '全职', tone: 'success' },
    PART_TIME: { label: '兼职', tone: 'info' },
    CONTRACT: { label: '合同制', tone: 'info' },
    INTERN: { label: '实习', tone: 'warning' },
  },
  'payroll.amountUnit': {
    MINOR_UNITS: { label: '最小货币单位', tone: 'info' },
  },
}

export function translate(domain: string, field: string, code: string | null | undefined): DictionaryEntry {
  if (code === null || code === undefined || code.trim() === '') return { label: '—' }
  const entry = DICTIONARY[`${domain}.${field}`]?.[code]
  if (entry !== undefined) return entry
  if (import.meta.env.DEV) console.warn(`[dictionary] unknown code ${domain}.${field}=${code}`)
  return { label: '未知', tone: 'warning' }
}
```

- [ ] **Step 4: 运行字典测试**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/dictionary.spec.ts
```

Expected: PASS，3 tests。

- [ ] **Step 5: 实现 `PageShell.vue` 与失败测试**

创建 `frontend/tests/unit/PageShell.spec.ts`：

```ts
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PageShell from '@/shared/ui/PageShell.vue'

describe('PageShell', () => {
  it('renders title, primary action slot and body', () => {
    const wrapper = mount(PageShell, {
      props: { title: '员工列表' },
      slots: { actions: '<button>新增员工</button>', default: '<p>列表内容</p>' },
    })
    expect(wrapper.text()).toContain('员工列表')
    expect(wrapper.text()).toContain('新增员工')
    expect(wrapper.text()).toContain('列表内容')
  })
})
```

创建 `frontend/src/shared/ui/PageShell.vue`：

```vue
<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <section class="page-shell">
    <header class="page-shell__header">
      <div>
        <h1>{{ title }}</h1>
        <p v-if="description" class="muted">{{ description }}</p>
      </div>
      <div class="page-shell__actions"><slot name="actions" /></div>
    </header>
    <div class="page-shell__body"><slot /></div>
  </section>
</template>

<style scoped>
.page-shell { display: flex; flex-direction: column; gap: 16px; }
.page-shell__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.page-shell__actions { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
```

- [ ] **Step 6: 实现表单弹窗/抽屉**

创建 `frontend/src/shared/ui/FormDialog.vue`：

```vue
<script setup lang="ts">
import { ElDialog } from 'element-plus'

const props = defineProps<{ modelValue: boolean; title: string; confirmLoading?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [] }>()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <ElDialog :model-value="props.modelValue" :title="props.title" width="640px" @close="close">
    <slot />
    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="props.confirmLoading" @click="emit('confirm')">保存</el-button>
    </template>
  </ElDialog>
</template>
```

创建 `frontend/src/shared/ui/FormDrawer.vue`，结构与 `FormDialog` 相同，但使用 `ElDrawer`、`size="520px"`。

- [ ] **Step 7: 运行展示层测试**

Run:

```bash
cd frontend && pnpm exec vitest run tests/unit/dictionary.spec.ts tests/unit/PageShell.spec.ts
```

Expected: PASS，4 tests。

- [ ] **Step 8: Commit**

```bash
git add frontend/src/shared/ui/PageShell.vue frontend/src/shared/ui/FormDialog.vue frontend/src/shared/ui/FormDrawer.vue frontend/src/shared/display/dictionary.ts frontend/tests/unit/dictionary.spec.ts frontend/tests/unit/PageShell.spec.ts
git commit -m "feat(ui): add shared page shell, form containers and Chinese dictionary"
```

---

### Task 8: 产品级门禁测试先行

**Files:**
- Create: `scripts/audit_page_model.mjs`
- Create: `scripts/audit_ui_primitives.mjs`
- Create: `scripts/audit_user_copy.mjs`
- Create: `scripts/tests/audit_page_model.test.mjs`
- Create: `scripts/tests/audit_ui_primitives.test.mjs`
- Create: `scripts/tests/audit_user_copy.test.mjs`
- Modify: `package.json`

**Interfaces:**
- Consumes: Task 4–7 的页面模型、清单、共享骨架。
- Produces: 三个可运行门禁；当前代码预期失败，失败清单作为第 2/3 册输入。

- [ ] **Step 1: 写页面模型门禁测试**

创建 `scripts/tests/audit_page_model.test.mjs`：

```js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditPageModel } from '../audit_page_model.mjs'

test('rejects cross-module tabs and action-like pages', () => {
  const result = auditPageModel({
    pages: [
      { name: 'employees', path: '/hr/employees', kind: 'workspace' },
      { name: 'create', path: '/hr/employees/create', kind: 'workspace' },
    ],
    tabs: [{ page: 'employees', labels: ['员工档案', '部门管理', '岗位管理'] }],
  })
  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /action-like path/)
  assert.match(result.failures.join('\n'), /cross-module tab 部门管理/)
})
```

- [ ] **Step 2: 实现页面模型门禁并确认测试通过**

创建 `scripts/audit_page_model.mjs`：

```js
const ACTION_LIKE = /\/(create|new|edit|editor|archive|restore|import|export)(\/|$)/
const CROSS_MODULE_LABELS = new Set(['部门管理', '岗位管理', '员工异动', '劳动合同', '员工档案'])

export function auditPageModel({ pages, tabs }) {
  const failures = []
  for (const page of pages) {
    if (ACTION_LIKE.test(page.path)) failures.push(`${page.name}: action-like path ${page.path}`)
  }
  for (const entry of tabs) {
    for (const label of entry.labels) {
      if (CROSS_MODULE_LABELS.has(label) && label !== entry.page) {
        failures.push(`${entry.page}: cross-module tab ${label}`)
      }
    }
  }
  return { ok: failures.length === 0, failures }
}
```

Run:

```bash
node --test scripts/tests/audit_page_model.test.mjs
```

Expected: PASS，1 test。

- [ ] **Step 3: 写裸控件门禁测试**

创建 `scripts/tests/audit_ui_primitives.test.mjs`：

```js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditUiPrimitives } from '../audit_ui_primitives.mjs'

test('rejects business pages with raw table/input/select', () => {
  const result = auditUiPrimitives({
    files: [
      { path: 'frontend/src/modules/platform/pages/SystemUsersPage.vue', source: '<table><input /><select /></table>' },
      { path: 'frontend/src/shared/ui/DataTable.vue', source: '<el-table />' },
    ],
  })
  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /SystemUsersPage\.vue: raw table/)
})
```

- [ ] **Step 4: 实现裸控件门禁并确认测试通过**

创建 `scripts/audit_ui_primitives.mjs`：

```js
const RAW_TAGS = ['<table', '<input', '<select', '<details']

export function auditUiPrimitives({ files }) {
  const failures = []
  for (const file of files) {
    if (!file.path.includes('/modules/')) continue
    if (file.path.includes('/components/') && !file.path.endsWith('Page.vue')) continue
    for (const tag of RAW_TAGS) {
      if (file.source.includes(tag)) failures.push(`${file.path}: raw ${tag.slice(1)}`)
    }
  }
  return { ok: failures.length === 0, failures }
}
```

Run:

```bash
node --test scripts/tests/audit_ui_primitives.test.mjs
```

Expected: PASS，1 test。

- [ ] **Step 5: 写用户文案门禁测试**

创建 `scripts/tests/audit_user_copy.test.mjs`：

```js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditUserCopy } from '../audit_user_copy.mjs'

test('rejects technical copy and English enums in user-facing templates', () => {
  const result = auditUserCopy({
    files: [
      { path: 'frontend/src/modules/platform/pages/SystemUsersPage.vue', source: '<p>角色来自 GET /v1/roles；状态 ACTIVE</p>' },
    ],
  })
  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /GET \/v1\//)
  assert.match(result.failures.join('\n'), /ACTIVE/)
})
```

- [ ] **Step 6: 实现用户文案门禁并确认测试通过**

创建 `scripts/audit_user_copy.mjs`：

```js
const FORBIDDEN = [
  { pattern: /GET \/v1\/|POST \/v1\/|PATCH \/v1\/|DELETE \/v1\//, label: 'API path' },
  { pattern: /\b(ACTIVE|INACTIVE|PROBATION|TERMINATED|FULL_TIME|PART_TIME|MINOR_UNITS)\b/, label: 'English enum' },
  { pattern: /\b(Wave|TODO|TBD)\b/, label: 'development marker' },
  { pattern: /P[0-3]_(PUBLIC|INTERNAL|SENSITIVE|RESTRICTED)/, label: 'field profile code' },
]

export function auditUserCopy({ files }) {
  const failures = []
  for (const file of files) {
    if (!file.path.endsWith('.vue')) continue
    if (file.path.includes('/tests/') || file.path.includes('/shared/ui/')) continue
    for (const rule of FORBIDDEN) {
      if (rule.pattern.test(file.source)) failures.push(`${file.path}: ${rule.label}`)
    }
  }
  return { ok: failures.length === 0, failures }
}
```

Run:

```bash
node --test scripts/tests/audit_user_copy.test.mjs
```

Expected: PASS，1 test。

- [ ] **Step 7: 接真实仓库并记录失败清单**

在 `package.json` 增加：

```json
"page-model:check": "node scripts/audit_page_model.mjs",
"ui-primitives:check": "node scripts/audit_ui_primitives.mjs",
"copy:check": "node scripts/audit_user_copy.mjs"
```

为三个脚本实现 `main()`，递归读取 `frontend/src/modules/**/*.vue` 与 `registry.ts`，打印 `ok` 与失败列表。

运行：

```bash
node scripts/audit_page_model.mjs
node scripts/audit_ui_primitives.mjs
node scripts/audit_user_copy.mjs
```

Expected: 当前代码至少在三项中产生真实失败；把输出保存到 `docs/audit/2026-11-26-v2-gate-baseline.md`。不得为了让门禁通过而放宽容忍列表。

- [ ] **Step 8: Commit**

```bash
git add scripts/audit_page_model.mjs scripts/audit_ui_primitives.mjs scripts/audit_user_copy.mjs scripts/tests/audit_page_model.test.mjs scripts/tests/audit_ui_primitives.test.mjs scripts/tests/audit_user_copy.test.mjs package.json docs/audit/2026-11-26-v2-gate-baseline.md
git commit -m "test(gates): add failing-by-default page, primitive and copy gates"
```

---

### Task 9: 契约冻结评审与第 1 册验收

**Files:**
- Create: `docs/audit/2026-11-26-v2-volume-1-acceptance.md`
- Modify: `docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md`

**Interfaces:**
- Consumes: Task 1–8 全部产物。
- Produces: 第 1 册退出证据；第 2 册可施工的冻结接口清单。

- [ ] **Step 1: 运行第 1 册全部门禁**

```bash
pnpm typecheck
pnpm lint
pnpm test:unit
node scripts/audit_page_components.mjs
node scripts/audit_frontend_modules.mjs
node scripts/audit_page_model.mjs
node scripts/audit_ui_primitives.mjs
node scripts/audit_user_copy.mjs
node --test scripts/tests/*.test.mjs
```

Expected: 新增契约测试全绿；页面/原语/文案门禁允许对旧页面失败，但失败清单必须与第 2/3 册任务一一对应。

- [ ] **Step 2: 运行后端契约测试**

```bash
cd backend
../.tools/php/php.exe artisan test --compact --filter='ActionManifestParityTest|PagePermissionCoverageTest|NoTenantNoLegalEntityTest'
```

Expected: 动作与页面契约测试通过；`NoTenantNoLegalEntityTest` 按预期失败，作为第 2 册输入。

- [ ] **Step 3: 写验收记录**

创建 `docs/audit/2026-11-26-v2-volume-1-acceptance.md`，包含：

```markdown
# 第 1 册验收记录

## 冻结接口
- PageSpec 字段与文件路径
- ActionSpec JSON 结构与生成命令
- 权限/API 对齐契约测试名
- 共享 UI 组件路径

## 命令与结果
（逐条粘贴命令、退出码、关键输出）

## 已知失败与归属
| 失败项 | 证据 | 归属册/任务 | 是否阻断第 2 册 |
|---|---|---|---|

## 结论
第 1 册是否达到退出条件：是/否；未达到时列出阻断项。
```

- [ ] **Step 4: 更新总控索引**

在 `docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md` 的批次表把第 1 册状态改为 `Complete`，并记录实际提交范围。

- [ ] **Step 5: 提交**

```bash
git add docs/audit/2026-11-26-v2-volume-1-acceptance.md docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md
git commit -m "docs(plan): accept V2.1 volume 1 contracts"
```

---

## 第 1 册退出条件

- 4 份 ADR 已写并被用户接受；V1.1、ADR-0025、AGENTS.md 无未加注冲突。
- 基线证据包含实际命令、退出码与失败输出。
- 160 条路由逐条有处置；140 项能力无未映射。
- 页面模型、动作清单、权限/OpenAPI 对齐契约测试可运行。
- 共享 UI 骨架与中文字典有单元测试。
- 三个新产品级门禁能对反例失败，并对真实旧页面给出可归属的失败清单。
- 第 2 册所需接口、文件路径、测试命令已冻结。
