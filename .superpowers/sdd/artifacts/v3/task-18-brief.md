### Task 18: 跨域回归与第 3 册验收

**Files:**
- Create: `docs/audit/2026-11-26-v2-volume-3-acceptance.md`
- Create: `frontend/tests/e2e/real/cross-domain.spec.ts`
- Modify: `docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md`

**Interfaces:**
- Consumes: Task 1–16 全部业务域。
- Produces: 跨域 E2E、性能基线、权限回归与第 3 册退出结论。

- [ ] **Step 1: 写跨域 E2E**

覆盖：招聘候选人录用 → 员工建档 → 异动调岗 → 合同续签 → 请假 → 考勤影响 → 薪资试算 → 绩效评分 → 培训记录 → 报表导出。

```ts
test('candidate to employee to payroll cross-domain journey', async ({ page }) => {
  await loginAs(page, 'demo.hr')
  // 候选人录用、员工详情、异动、合同、请假、薪资、绩效、培训、报表
  // 每步断言真实业务状态，不使用固定 sleep
})
```

- [ ] **Step 2: 运行跨域 API 与 E2E**

```bash
cd backend && ../.tools/php/php.exe artisan test --compact
cd ../frontend && pnpm typecheck && pnpm lint && pnpm test:unit && HRMS_FRONTEND_URL=http://127.0.0.1:5174 HRMS_BACKEND_URL=http://127.0.0.1:8080 \
  pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/cross-domain.spec.ts
```

Expected: 后端全量通过；前端全量通过；跨域 E2E 通过；未通过项阻断第 4 册。

- [ ] **Step 3: 记录性能基线**

在 `docs/audit/2026-11-26-v2-volume-3-acceptance.md` 记录：

- 员工列表 1k/10k 数据分页响应；
- 报表导出 1k/10k 行耗时；
- 薪资批次 1k 人试算耗时；
- 数据库索引与慢查询证据。

- [ ] **Step 4: 更新总控并提交**

```bash
git add docs/audit/2026-11-26-v2-volume-3-acceptance.md frontend/tests/e2e/real/cross-domain.spec.ts docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md
git commit -m "docs(plan): accept V2.1 volume 3 business domains"
```

---

## 第 3 册退出条件

- 员工原子建档、列表、详情、子档案、附件、变更、导出全部闭环。
- 部门、岗位、异动、合同、考勤、假勤、加班、出差、薪资、绩效、招聘、培训、报表、公告均有真实读写与中文展示。
- 12 个平台与系统管理工作台已独立实现，具备真实 API、五态、动作权限与中文展示。
- 每个业务域至少一条 E2E 与一项权限负测；数据范围与字段策略在 API 层生效。
- 跨域旅程通过：候选人 → 员工 → 异动 → 合同 → 假勤 → 薪资 → 绩效 → 培训 → 报表。
- 无跨模块 Tab；无假入口；无英文枚举、接口路径、表名、字段档位或演示文案。
- 第 4 册所需的共享壳退役清单、真实栈脚本与验收账号已冻结；第 4 册只做收敛、清理与发布验收，不重复实现业务页。
