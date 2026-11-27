# FactoryHR V2.3 模块闭环整改 — 总控计划

- **状态：** Proposed — 待用户确认
- **日期：** 2026-11-27
- **代码基线：** `add6d49`
- **设计规格：** `docs/superpowers/specs/2026-11-27-factoryhr-v2-3-module-closure-design.md`
- **处置矩阵：** `docs/v2.3-remediation/2026-11-27-155-entry-disposition-matrix.md`
- **执行方式：** `superpowers:subagent-driven-development`（推荐）或 `superpowers:executing-plans`
- **模块计划：** `docs/superpowers/plans/2026-11-27-v2-3-m01-...md` 至 `m21`

---

## 1. 计划结构

本计划不是“再列一遍 155 个 URL”，而是按 21 个业务模块组织施工。每个模块计划必须包含：

1. 当前事实审计：真实页面、真实 API、真实表、真实测试与缺口；
2. 目标闭环：业务对象、状态机、角色、主体、输入输出事实；
3. 破坏性删除：旧路由、旧组件、旧 config、旧测试、旧 OpenAPI；
4. 后端命令：迁移、服务、控制器、OpenAPI、capability、审计；
5. 前端页面：主工作区、Tab、Dialog/Drawer、六态、中文字典；
6. 权限矩阵：角色 × 动作 × 数据范围 × 字段；
7. 测试：单元、契约、权限负测、真实浏览器 E2E；
8. 证据：命令、退出码、日志、截图、数据前后快照；
9. 退出检查：未完成项与阻断条件。

---

## 2. 执行顺序

V2.3 推荐依赖链必须落实到模块顺序。共享底座先于业务页面；权限与组织先于员工；员工先于异动、合同、假勤、薪资；假勤先于薪资；人才与报表最后。

| 批次 | 模块 | 依赖理由 |
|---|---|---|
| D0 | M01、M21 | 登录、账号、系统设置、字典、日历是所有业务的前置 |
| D1 | M19 | 页面、动作、数据范围、字段策略是一切页面的授权底座 |
| D2 | M05、M06、M04 | 组织、岗位、员工主数据先稳定 |
| D3 | M20、M07、M08 | 审批内核与入转调离、合同先闭环 |
| D4 | M09、M10、M11、M12 | 考勤、请假、加班、出差形成时间事实 |
| D5 | M14、M13 | 福利参数与薪资核算依赖已锁定的时间事实 |
| D6 | M16、M17、M15、M18 | 招聘、培训、绩效、公告形成人才与沟通闭环 |
| D7 | M02、M03 | 看板与报表最后读取已闭环事实 |
| D8 | 全系统 | 12 条跨域 E2E、五角色 UAT、真实栈与发布判定 |

> 批次是依赖顺序，不是分期上线。所有模块必须完成，不允许把 D6/D7 留作“二期”。

---

## 3. 跨模块冻结契约

以下契约在 M01 执行时冻结，后续模块不得改名或改变语义。

### 3.1 页面身份

```ts
type PageKind = 'workspace' | 'detail' | 'governance' | 'base'
type PageState = 'loading' | 'empty' | 'unconfigured' | 'forbidden' | 'error' | 'success'
type PageActionSpec = {
  key: string
  label: string
  capability: string
  api: string
  ui: 'primary' | 'secondary' | 'danger' | 'hidden'
  confirm: 'none' | 'danger' | 'sod'
}
```

- 每个 `page_code` 唯一；
- 每个 `workspace` / `governance` 最多一个侧栏入口；
- 每个 `detail` 只能通过稳定 URL 进入；
- 每个 `actionKey` 必须全局唯一。

### 3.2 动作清单

机器契约：`docs/generated/page-actions.json`
前端消费：`frontend/src/generated/pageActions.ts`
生成器：`scripts/generate_action_manifest.mjs`

新增约束：

- 任何写 API 若无页面动作引用，门禁失败；
- 任何页面动作若无 OpenAPI operation，门禁失败；
- 任何动作若无负向权限测试，门禁失败；
- 删除页面必须同步删除其动作，不得留下孤儿能力。

### 3.3 权限链路

```text
role_pages（页面进入权）
  ∧ page.required_capability
  ∧ action capability grant
  ∧ AuthorityBinding（anchor + 有效期 + 版本）
  ∧ FieldProfile（读/写/导出）
  ∧ 业务状态机
  → 服务端允许或稳定拒绝
```

### 3.4 主体分离

- `actor_user_id`：操作者；
- `subject_employment_id`：业务对象；
- `approver_user_id`：审批者。

本人申请固定 `subject = session.employment_id`；HR 代申请必须使用独立命令并显式选择授权员工；审批只能由真实待办人执行。

### 3.5 金额与时间

- 金额：整数最小单位；费率：十进制字符串，至少 1e6 缩放；
- 时间：UTC 存储；业务日期按 `company_profile.timezone` 与共享日历；
- 界面金额统一“元”格式化，禁止直接显示 `MINOR_UNITS`。

---

## 3.6 页面计数与批次基线

页面数量在所有模块间是**递减序列**，不允许每个模块从 155 重算。执行时必须先建立 `scripts/v23-page-count.json`，记录每一批完成后的前后端页面数；该文件是测试固定值的唯一来源。

| 批次 | 删除入口 | 新增页面 | 前端 registry 预期 | 后端 catalog 预期 |
|---|---|---:|---:|---:|
| 基线 | — | — | 155 | 153 |
| D7（M02/M03 后） | 13 | 0 | 142 | 140 |
| D1/D2/D3/D4/D5/D6 | 视模块计划 | `/system/pages` +1 | 按批递减 | 按批递减 |
| D8（M19/M21 后） | M19 4、M21 1 | M19 +1 | 151 | 149 |

规则：

- 每模块开始前读取当前 `v23-page-count.json`，结束后写回新值；
- 测试固定值必须从该文件读取或在测试内引用同一常量，不得散落硬编码；
- M19 的中间值以后端 150、前端 152 为准，M21 再减到 149/151；
- 任何模块结束后计数不符，门禁失败并停止后续合并。

## 4. 共享门禁

### 4.1 新增门禁

新增 `scripts/audit-v23-module-closure.mjs`，检查：

1. 155 条矩阵与 registry 双向一致；
2. 每个模块计划存在并引用真实文件；
3. 每个保留页面至少一个 `requiredApis`；
4. 每个写 API 有页面动作；
5. 每个动作有 capability 与 OpenAPI operation；
6. 每个动作有 E2E 用例 ID；
7. 被删除页面无残留引用；
8. 每模块至少一条跨域 E2E。

### 4.2 既有门禁

每个模块退出必须运行：

```bash
pnpm gate:all
```

并附退出码与完整日志。禁止用 `artisan test` 替代 `.tools/php/php.exe vendor/bin/phpunit`。

### 4.3 真实栈门禁

模块涉及读写、权限或迁移时，必须运行：

```bash
cd backend
php artisan migrate:fresh --seed
php artisan serve --port=8000
```

并在真实浏览器执行该模块 E2E。仅运行 Vitest 或 Playwright `--list` 不算通过。

---

## 5. 证据目录

每模块固定证据位置：

```text
docs/implementation/MXX/
  01-current-audit.md
  02-domain-and-ui-contract.md
  03-implementation-plan.md
  04-evidence.md
  evidence/
    backend-tests.log
    frontend-tests.log
    gate-all.log
    e2e/
      *.png
      trace.zip
    data-before.json
    data-after.json
```

`04-evidence.md` 必须逐项列命令、退出码、关键输出、截图路径与未执行项。未执行必须写 `NOT RUN`，不得写“通过”。

---

## 6. 模块退出检查

每个模块必须全部满足：

- [ ] 矩阵中该模块的 `KEEP` 页面完成读写闭环；
- [ ] 矩阵中该模块的 `FOLD` 页面已删除，能力已迁入目标页面；
- [ ] 旧路由、旧组件、旧 config、旧测试无残留；
- [ ] OpenAPI、capability、动作清单、页面注册表一致；
- [ ] 至少一条真实浏览器 E2E 通过；
- [ ] 权限负测返回 403；
- [ ] 六态与中文文案检查通过；
- [ ] `pnpm gate:all` 通过；
- [ ] 证据文件完整；
- [ ] 无 Critical/Important 未关闭评审项。

任何一项未满足，模块状态为 `BLOCKED`，不得进入下游模块的合并。

---

## 7. 全系统退出检查

- [ ] 26 个侧栏主工作区与 7 个详情深链全部完成；
- [ ] 120 条 `FOLD` 入口删除且能力迁移完成；
- [ ] 12 条跨域 E2E 真实执行通过；
- [ ] 五角色 UAT 通过；
- [ ] 真实栈部署、性能、备份恢复、回滚演练通过；
- [ ] `pnpm gate:all` 通过；
- [ ] 发布判定为 GO。

---

## 8. 风险与控制

| 风险 | 控制 |
|---|---|
| 21 份计划再次变成文档工程 | 每模块固定 4 份证据文件与真实门禁；未执行即阻断 |
| 删除入口造成功能丢失 | 矩阵逐条目标映射；先迁移后删除；能力覆盖校验 |
| 共享壳拆分导致回归 | 每模块只迁一个对象；先补测试再删旧壳 |
| 权限只改前端 | 每个动作配 403 负测；撤销权限后旧标签页再次提交 |
| 新写命令绕过状态机 | 复用 CommandRunner、幂等键、审计与领域服务 |
| 迁移损坏历史 | 前向迁移、备份、演练、恢复校验、双人评审 |
| 金额中文化掩盖单位错误 | 统一金额格式化；E2E 断言元与分转换 |

---

## 9. 模块计划索引

| 模块 | 计划文件 |
|---|---|
| M01 | `2026-11-27-v2-3-m01-identity-self-service.md` |
| M02 | `2026-11-27-v2-3-m02-dashboard.md` |
| M03 | `2026-11-27-v2-3-m03-reporting.md` |
| M04 | `2026-11-27-v2-3-m04-employee-records.md` |
| M05 | `2026-11-27-v2-3-m05-organization.md` |
| M06 | `2026-11-27-v2-3-m06-positions.md` |
| M07 | `2026-11-27-v2-3-m07-lifecycle.md` |
| M08 | `2026-11-27-v2-3-m08-contracts.md` |
| M09 | `2026-11-27-v2-3-m09-attendance.md` |
| M10 | `2026-11-27-v2-3-m10-leave.md` |
| M11 | `2026-11-27-v2-3-m11-overtime.md` |
| M12 | `2026-11-27-v2-3-m12-travel.md` |
| M13 | `2026-11-27-v2-3-m13-payroll.md` |
| M14 | `2026-11-27-v2-3-m14-benefits.md` |
| M15 | `2026-11-27-v2-3-m15-performance.md` |
| M16 | `2026-11-27-v2-3-m16-recruitment.md` |
| M17 | `2026-11-27-v2-3-m17-training.md` |
| M18 | `2026-11-27-v2-3-m18-engagement.md` |
| M19 | `2026-11-27-v2-3-m19-iam.md` |
| M20 | `2026-11-27-v2-3-m20-workflow.md` |
| M21 | `2026-11-27-v2-3-m21-platform-settings.md` |
