# FactoryHR V2.3 M15｜绩效考核 — 详细实施计划
> **状态：Proposed — 待用户确认。** 本计划不得在规格批准前执行。
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
**Goal:** 将 `/performance/templates`、`/performance/indicators`、`/performance/goals`、`/performance/reviews`、`/performance/feedback`、`/performance/calibration`、`/performance/results`、`/performance/appeals`、`/performance/reports` 九个旧入口折叠进 `/performance` 的周期工作区与周期详情，补齐“发布冻结结果、申诉必须关联已发布结果”的服务端规则，同时保留既有校准/评审投影能力。
**Architecture:** `Talent/Performance` 继续独占 `performance_cycles`、`performance_templates`、`performance_indicators`、`performance_goals`、`performance_reviews`、`performance_calibrations`、`performance_feedback`、`performance_appeals`。现有 `PerformanceCycleService::publish()` 已校验“所有 review 已提交”并冻结后续编辑，本计划复用并补强“发布结果快照 + 申诉状态机”；前端收敛为 `/performance` 周期列表和 `/performance/cycles/:id` 周期详情（作为同一工作区中的详情视图，不是侧栏入口）。
**Tech Stack:** Laravel 13 / PHP 8.3 / MySQL 8.4 / Vue 3.5 / TypeScript / Element Plus / PHPUnit / Vitest / Playwright / OpenAPI 3.1。
## Global Constraints
- 规范优先级：用户裁决 > 本计划 > V2.3 M15 模块规格 > V2.1 设计 > 历史文档。
- 旧 URL 不重定向；9 个 `FOLD` 入口必须删除并登记 Tab/详情目的地。
- `Talent/Performance` 是唯一写入者；Payroll/Reports 只能通过 Contracts/投影读取，不能 join 写绩效表。
- 每个写动作有 capability、OpenAPI operation、审计、403 负测；新增写命令使用 `CommandRunner`/`IdempotencyRunner` 与 Idempotency-Key。
- 分数是服务端字符串/整数证据，不在前端做二进制浮点运算；发布后结果不可改写；未执行测试一律写 `NOT RUN`。
## 1. 模块事实
### 1.1 KEEP 页面与当前文件
| 目标 | 路由 | 当前文件 | 处置 |
|---|---|---|---|
| 绩效周期工作区 | `/performance` | `frontend/src/modules/talent/pages/PerformanceCyclesPage.vue` + `frontend/src/modules/talent/performance/pages/PerformanceWorkspacePage.vue` | 收敛为周期列表；详情嵌入同一工作区 |
| 周期详情 | `/performance/cycles/:id`（本轮新增稳定深链） | `frontend/src/modules/talent/performance/pages/PerformanceCycleDetailPage.vue` | 吸收模板/指标/目标/评价/反馈/校准/结果/申诉/报表 Tab |
| 页面注册 | `/performance` | `frontend/src/router/registry.ts` | 仅周期列表入口；详情为 `kind: detail` |
| 组件映射 | `performance/index` | `frontend/src/modules/talent/pages/index.ts` | 指向周期列表，不再走 `TalentWorkspacePage` 旧壳 |
### 1.2 FOLD 删除与能力目的地
| 原路由 | 旧文件（`frontend/src/modules/talent/pages/`） | 能力目的地 |
|---|---|---|
| `/performance/templates` | `PerformanceTemplatesPage.vue` | `/performance/cycles/:id?tab=templates` |
| `/performance/indicators` | `PerformanceIndicatorsPage.vue` | `?tab=indicators` 指标库 |
| `/performance/goals` | `PerformanceGoalsPage.vue` | `?tab=goals` 目标计划 |
| `/performance/reviews` | `PerformanceReviewsPage.vue` | `?tab=reviews` 自评/主管评分 |
| `/performance/feedback` | `PerformanceFeedbackPage.vue` | `?tab=feedback` 360 反馈 |
| `/performance/calibration` | `PerformanceCalibrationPage.vue` | `?tab=calibration` 校准 |
| `/performance/results` | `PerformanceResultsPage.vue` | `?tab=results` 发布/冻结结果 |
| `/performance/appeals` | `PerformanceAppealsPage.vue` | `?tab=appeals` 申诉/处理 |
| `/performance/reports` | `PerformanceReportsPage.vue` | `?tab=reports` + `/reports` |
### 1.3 当前缺陷（源码路径）
1. `backend/app/Modules/Talent/Services/PerformanceCycleService.php::publish()` 已要求全部 review `SUBMITTED`、置 `PUBLISHED` 并拒绝后续提交（**核心冻结已存在，不重写**），但未留发布快照；`results()` 实时读当前 ratings，`reports()` 不排除 DRAFT。
2. `backend/app/Modules/Talent/Services/PerformanceService.php::createAppeal()` 只断言 `review.status === 'SUBMITTED'`，不检查 cycle `PUBLISHED`；`backend/app/Modules/Talent/Domain/PerformanceStateMachine.php::canAppeal()` 不接收 cycle 状态，未发布周期可申诉。
3. `backend/app/Modules/Talent/Http/Controllers/PerformanceController.php::storeAppeal()` 只校验 `performance.appeal.manage`，不校验本人/已发布结果；`storeReview()` 的 `employment_id` 来自请求，未明确本人自评与主管评分分离。
4. `frontend/src/modules/talent/pages/PerformanceCyclesPage.vue` 仍是 `TalentWorkspacePage` 旧壳，`frontend/src/modules/talent/performance/pages/PerformanceWorkspacePage.vue` 是另一套实现；详情用 `/performance?cycle_id=` 而非稳定深链，九个 `Performance*Page.vue` 只有通用表格/表单，`frontend/src/modules/talent/catalog.ts` 仍指向旧读路径。
## 2. 目标契约
### 2.1 页面结构
```text
/performance
└── 绩效周期列表：名称/期间/状态筛选、创建、进入周期
/performance/cycles/:id
├── 概览 · 模板 · 指标库 · 目标计划 · 考核评价
├── 360 反馈 · 绩效校准 · 结果发布 · 申诉 · 报表
└── 每 Tab 按状态/角色显示读写；发布后评分只读
```
`?tab=` 是详情页内部状态，非法值回落 `overview`。六态 `loading | empty | unconfigured | forbidden | error | success`；周期未配置模板/指标时 `unconfigured`，不显示空表头。
### 2.2 DTO / API
| 操作 | 状态 | Capability | 说明 |
|---|---|---|---|
| `GET/POST /v1/performance/cycles` | 现有 | `performance.cycle.read/manage` | 列表/创建周期 |
| `GET/POST /v1/performance/templates` | 现有 | `performance.cycle.read/manage` | 模板版本，权重合计 100 |
| `GET/POST /v1/performance/indicators` | 现有 | `performance.indicator.manage` | 指标库/创建 |
| `GET/POST /v1/performance/goals` | 现有 | `performance.goal.manage` | 目标投影/保存，归属 cycle+employee |
| `GET/POST /v1/performance/reviews` | 现有，修正 | `performance.review.manage` | 主管/HR 列表/草稿；本人自评需 SELF |
| `POST /v1/performance/reviews/{id}/submit` | 现有 | `performance.review.submit` | DRAFT→SUBMITTED；发布后 409 |
| `GET/POST /v1/performance/feedback` | 现有 | `performance.feedback.manage` | 360 反馈投影/记录 |
| `GET /v1/performance/calibration` | 现有 | `performance.calibration.manage` | 校准投影 |
| `POST /v1/performance/reviews/{id}/calibration` | 现有 | `performance.calibration.manage` | 校准评分 + 原因 |
| `POST /v1/performance/cycles/{id}/publish` | 现有，扩展 | `performance.result.publish` | 校验全部提交；写发布快照；冻结结果 |
| `GET /v1/performance/results` | 现有，重构 | `performance.result.publish` / `performance.cycle.read` | 优先返回发布快照；未发布时实时投影并标 `pending` |
| `POST /v1/performance/reviews/{id}/appeals` | 现有，修正 | `performance.appeal.manage` | 必须 cycle PUBLISHED 且 review 有发布快照 |
| `GET /v1/performance/reports` | 现有，扩展 | `performance.report.read` | 仅 PUBLISHED 结果进入分布/趋势 |
发布快照响应（**新增字段，不新增第二发布命令**）：`{cycle_id,status:"PUBLISHED",published_at,published_by,results:[{review_id,employment_id,self_rating,manager_rating,calibrated_rating,final_rating}]}`。`results[]` 存 `performance_result_snapshots`（只 append）；`GET /results` 发布后读快照，未发布返回实时投影并标 `pending`；`final_rating` 由服务端规则决定。
### 2.3 状态机
```text
周期：DRAFT -> ACTIVE -> PUBLISHED（终态；无 reopen）
评估：DRAFT -> SUBMITTED -> APPEALED
              └──────> 发布后冻结（不可改）
校准：append-only，仅 ACTIVE 周期可写；发布后禁止
申诉：PUBLISHED result + SUBMITTED review -> OPEN -> RESOLVED/REJECTED
```
- `publish()` 只允许全部 review `SUBMITTED`，发布后 `assertCanSubmit()` 仍 409 `CYCLE_PUBLISHED`；核心冻结已存在，不重写。
- 发布时写 `performance_result_snapshots`，之后 `results` 读快照；申诉必须 cycle `PUBLISHED` 且有快照，未发布 409 `APPEAL_REQUIRES_PUBLISHED_RESULT`。
- 申诉只改申诉状态，不重写三项评分；处理结果另存 append-only 记录。
### 2.4 权限与字段
- 页面进入 `performance.cycle.read`；模板/周期写 `performance.cycle.manage`；指标 `performance.indicator.manage`；目标 `performance.goal.manage`；评价 `performance.review.manage/submit`；反馈 `performance.feedback.manage`；校准 `performance.calibration.manage`；发布 `performance.result.publish`；申诉 `performance.appeal.manage`；报表 `performance.report.read`。
- 新增 `performance.result.publish` 已存在；不新增重复能力。`GET /results` 对无 publish 能力但持 cycle.read 的本人只返回本人已发布结果，不返回全公司名单。
- SELF 仅本人自评/本人结果；MANAGED 仅直属；ORG_UNIT/ORG_SUBTREE/COMPANY 按 Authorizer；越界/不存在 404；评分/申诉材料 P2。
## 3. 任务
### Task 1: 发布结果快照 forward migration
**Files:** Create: `backend/database/migrations/2026_11_27_000050_create_performance_result_snapshots.php`; Create: `backend/app/Modules/Talent/Models/PerformanceResultSnapshot.php`; Test: `backend/tests/Feature/Migration/PerformanceResultSnapshotMigrationTest.php`
**Interfaces:**
- Consumes: `performance_cycles`、`performance_reviews`。; Produces: `performance_result_snapshots`（`cycle_id, review_id, employment_id, self_rating, manager_rating, calibrated_rating, final_rating, published_at, published_by`）与模型；`cycle_id+review_id` 唯一，append-only。
- [ ] **Step 1: 写失败迁移测试**
```php
public function test_result_snapshot_table_is_append_only_shape(): void
{
    $this->assertTrue(Schema::hasTable('performance_result_snapshots'));
    $this->assertTrue(Schema::hasColumns('performance_result_snapshots', [
        'cycle_id', 'review_id', 'employment_id', 'final_rating', 'published_at', 'published_by',
    ]));
    $this->assertTrue(Schema::hasIndex('performance_result_snapshots', ['cycle_id', 'review_id'], 'unique'));
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter PerformanceResultSnapshotMigrationTest` Expected: FAIL，表不存在。
- [ ] **Step 3: 写匿名迁移**
```php
Schema::create('performance_result_snapshots', function (Blueprint $table): void {
    $table->id(); $table->unsignedBigInteger('cycle_id')->index(); $table->unsignedBigInteger('review_id')->index();
    $table->unsignedBigInteger('employment_id')->index(); $table->string('self_rating', 16)->nullable();
    $table->string('manager_rating', 16)->nullable(); $table->string('calibrated_rating', 16)->nullable();
    $table->string('final_rating', 16); $table->dateTime('published_at', 6); $table->unsignedBigInteger('published_by');
    $table->timestamps(6); $table->unique(['cycle_id', 'review_id'], 'performance_result_snapshot_unique');
});
```
回填：只对已存在 `performance_cycles.status=PUBLISHED` 的 cycle 写快照，`published_at` 取 cycle `updated_at`（缺失时用 `recorded_at`），`final_rating` 按既有 `COALESCE(calibrated_rating, manager_rating, self_rating)` 规则；不得把 DRAFT/ACTIVE 当已发布写入。
- [ ] **Step 4: 运行迁移测试并提交**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter PerformanceResultSnapshotMigrationTest` Expected: PASS。Run: `cd backend && ../.tools/php/php.exe artisan migrate:fresh --seed`；Expected: exit 0。
```bash
git add backend/database/migrations backend/app/Modules/Talent/Models backend/tests/Feature/Migration
git commit -m "feat(performance): add published result snapshots"
```
### Task 2: 发布写快照与结果读模型冻结
**Files:** Modify: `backend/app/Modules/Talent/Services/PerformanceCycleService.php`, `backend/app/Modules/Talent/Http/Controllers/PerformanceCycleController.php`, `PerformanceController.php`（同目录）, `docs/openapi/openapi.yaml`; Test: `backend/tests/Feature/Talent/PerformancePublishSnapshotTest.php`, `PerformanceCycleTest.php`（补发布后读模型断言）
**Interfaces:**
- Consumes: 既有 `PerformanceCycleService::publish()` 的提交校验、`PerformanceReview`、`PerformanceCalibration`、`Clock`、`AuditRecorder`。; Produces: `PerformanceCycleService::publish()` 在 cycle 置 PUBLISHED 的同事务 append `performance_result_snapshots`；`results()` 发布后读快照并返回 `snapshot=true`；`reports()` 只统计已发布快照。
- [ ] **Step 1: 写失败测试**
```php
public function test_publish_freezes_result_snapshots_and_reports_ignore_drafts(): void
{
    $cycle = $this->createPerformanceCycle();
    $review = $this->createReview($cycle, 1, selfRating: '4');
    $review->forceFill(['status' => 'SUBMITTED', 'manager_rating' => '4', 'calibrated_rating' => '3.5', 'submitted_at' => $this->clock->now()])->save();
    $this->postJson("/v1/performance/cycles/{$cycle->id}/publish", [], ['Idempotency-Key' => 'perf-pub-1'])->assertOk();
    $this->assertDatabaseHas('performance_result_snapshots', [
        'cycle_id' => $cycle->id, 'review_id' => $review->id, 'final_rating' => '3.5',
    ]);
    $this->getJson('/v1/performance/results?as_of=2026-11-27')->assertOk()->assertJsonPath('data.snapshot', true);
    $this->getJson('/v1/performance/reports?as_of=2026-11-27')->assertOk()->assertJsonPath('data.items.0.published_reviews', 1);
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'PerformancePublishSnapshotTest|PerformanceCycleTest'` Expected: FAIL，snapshot 表为空/结果读模型无 `snapshot`。
- [ ] **Step 3: 在既有事务内写快照**
```php
$cycle->forceFill(['status' => 'PUBLISHED', 'published_at' => $this->clock->now(), 'published_by' => $actorUserId])->save();
foreach ($reviews as $review) {
    PerformanceResultSnapshot::query()->create([
        'cycle_id' => (int) $cycle->id, 'review_id' => (int) $review->id, 'employment_id' => (int) $review->employment_id,
        'self_rating' => $review->self_rating, 'manager_rating' => $review->manager_rating, 'calibrated_rating' => $review->calibrated_rating,
        'final_rating' => (string) (($review->calibrated_rating ?? $review->manager_rating ?? $review->self_rating) ?? '0'),
        'published_at' => $this->clock->now(), 'published_by' => $actorUserId,
    ]);
}
```
`publish()` 的既有重复发布/未提交校验保持不变；不得新增第二发布 API。快照表写后不可更新/删除（模型 booted 抛错或迁移触发器）。`results()`：cycle PUBLISHED 时只读 snapshots，未发布时保持实时投影并返回 `snapshot:false`；`reports()` 计数只来自 snapshots，增加 `published_reviews`，DRAFT 不计入完成率。
- [ ] **Step 4: OpenAPI 与权限**
`POST /v1/performance/cycles/{id}/publish` 响应增加 `published_at/published_by/results`，`GET /results`、`GET /reports` 增加 `snapshot`/`published_reviews` 字段；`x-capability` 保持不变。新增负测：无 `performance.result.publish` 403；已发布第二次 publish 409 `CYCLE_PUBLISHED`；未全提交 409 `REVIEWS_NOT_SUBMITTED`；同键 replay 200。
- [ ] **Step 5: 运行测试与契约并提交**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'Performance(PublishSnapshot|Cycle|Workspace)Test'` Expected: PASS。Run: `pnpm openapi:validate`；Expected: OK。
```bash
git add backend docs
git commit -m "feat(performance): freeze published results and report only snapshots"
```
### Task 3: 申诉必须关联已发布结果与本人/权限边界
**Files:** Modify: `backend/app/Modules/Talent/Domain/PerformanceStateMachine.php`, `backend/app/Modules/Talent/Services/PerformanceService.php`, `backend/app/Modules/Talent/Http/Controllers/PerformanceController.php`, `docs/openapi/openapi.yaml`; Test: `backend/tests/Feature/Talent/PerformanceAppealPublishedTest.php`, `PerformanceAppealAuthorizationTest.php`
**Interfaces:**
- Consumes: `PerformanceResultSnapshot`、`PerformanceCycle`、`PerformanceReview`、`WorkflowCaseGateway::createCase()`。; Produces: `PerformanceStateMachine::assertCanAppeal(string $reviewStatus, string $cycleStatus)`（签名替换现有 `assertCanAppeal(string $status)`）；`createAppeal()` 要求 cycle `PUBLISHED` + snapshot；`POST /v1/performance/reviews/{id}/appeals` 仅本人/授权 HR 可创建。
- [ ] **Step 1: 写失败测试**
```php
public function test_appeal_requires_published_snapshot_and_non_self_handler(): void
{
    $cycle = $this->createPerformanceCycle();
    $review = $this->createReview($cycle, 1, selfRating: '4');
    $review->forceFill(['status' => 'SUBMITTED', 'submitted_at' => $this->clock->now()])->save();
    $this->actingAsEmployee(1);
    $this->postJson("/v1/performance/reviews/{$review->id}/appeals", [
        'reason' => 'Synthetic unpublished appeal',
    ], ['Idempotency-Key' => 'appeal-unpub'])->assertStatus(409)
        ->assertJsonPath('data.reason', 'APPEAL_REQUIRES_PUBLISHED_RESULT');
    $this->publishCycle($cycle);
    $this->postJson("/v1/performance/reviews/{$review->id}/appeals", [
        'reason' => 'Synthetic published appeal',
    ], ['Idempotency-Key' => 'appeal-pub'])->assertCreated();
    $this->assertDatabaseHas('performance_appeals', ['review_id' => $review->id, 'status' => 'OPEN']);
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'PerformanceAppeal(Published|Authorization)Test'` Expected: FAIL，未发布申诉当前返回 201。
- [ ] **Step 3: 修正状态机与申诉命令**
```php
public function assertCanAppeal(string $reviewStatus, string $cycleStatus): void
{
    if ($cycleStatus !== 'PUBLISHED') {
        throw new ConflictException('An appeal requires a published result.', ['reason' => 'APPEAL_REQUIRES_PUBLISHED_RESULT']);
    }
    if (! in_array($reviewStatus, ['SUBMITTED', 'APPEALED'], true)) {
        throw new ConflictException('Only a published submitted review can be appealed.', ['reason' => 'INVALID_APPEAL_TRANSITION', 'status' => $reviewStatus]);
    }
}
```
`createAppeal()` 在锁内查 cycle + review + snapshot；无 snapshot/cycle 非 PUBLISHED 409；同一 review 已有 `OPEN` 申诉 409；只写 `performance_appeals` 与 Workflow Case，不改评分。控制器校验：SELF 只能申诉自己的 review；HR 必须 `performance.appeal.manage` + 范围；处理人不能是本人（SOD，由 Workflow 模板/决策服务校验，并在测试中加用例）。
- [ ] **Step 4: 更新 OpenAPI/动作/负测**
OpenAPI 的 appeal 请求/响应补 `409 APPEAL_REQUIRES_PUBLISHED_RESULT`、`INVALID_APPEAL_TRANSITION`；`action-input.json` 保留/补齐 `performance.appeal.create`，pageCode 为 `performance-cycle-detail`；负测：无能力 403、跨范围 404、未发布 409、重复 409、本人处理 409/403、伪造 `actor_user_id` 被忽略。
- [ ] **Step 5: 跑测试并提交**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'Performance(Appeal|Cycle|Workspace)Test'` Expected: PASS。Run: `pnpm actions:generate && pnpm actions:check`；Expected: OK。
```bash
git add backend frontend docs
git commit -m "feat(performance): require published result for appeals"
```
### Task 4: `/performance` 工作区与周期详情 Tab 前端
**Files:** Modify: `frontend/src/modules/talent/pages/PerformanceCyclesPage.vue`, `frontend/src/modules/talent/performance/pages/PerformanceWorkspacePage.vue`, `PerformanceCycleDetailPage.vue`（同目录）, `frontend/src/modules/talent/performance/api.ts`, `frontend/src/router/registry.ts`; Create: `frontend/src/modules/talent/performance/components/PerformanceTemplatesPanel.vue`, `PerformanceIndicatorsPanel.vue`, `PerformanceGoalsPanel.vue`, `PerformanceFeedbackPanel.vue`, `PerformanceCalibrationPanel.vue`, `PerformanceResultsPanel.vue`, `PerformanceAppealsPanel.vue`, `PerformanceReportsPanel.vue`（同目录）; Test: `frontend/tests/unit/PerformanceWorkspaceTabs.spec.ts`, `PerformanceWorkspace.spec.ts`
**Interfaces:**
- Consumes: 现有 `listPerformanceCycles/listPerformanceReviews/publishPerformanceCycle/...` 与 Task 2/3 新字段；`PageShell`、`DataTable`、`ElDialog`、`ElDrawer`。; Produces: `/performance` 周期列表、`/performance/cycles/:id?tab=...` 稳定详情；test ids `performance-tabs`、`performance-result-published`、`performance-appeal-button`。
- [ ] **Step 1: 写失败 Tab/路由测试**
```ts
it('renders one cycle list and a stable cycle detail route', async () => {
  expect(PAGE_REGISTRY.filter((p) => p.path === '/performance').length).toBe(1)
  expect(PAGE_REGISTRY.some((p) => p.path === '/performance/cycles/:id')).toBe(true)
  const wrapper = await mountPerformance('/performance/cycles/9?tab=results', ['performance.cycle.read'])
  expect(wrapper.find('[data-testid="performance-tabs"]').exists()).toBe(true)
})
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd frontend && pnpm test:unit -- PerformanceWorkspaceTabs.spec.ts` Expected: FAIL，当前只有 `/performance?cycle_id=` 查询，没有稳定详情路由。
- [ ] **Step 3: 收敛工作区与详情 Tab**
`PerformanceCyclesPage.vue` 只保留周期列表/创建；`PerformanceWorkspacePage.vue` 作为 `/performance` 的列表容器，不再用 `cycle_id` query；点击周期 `router.push(`/performance/cycles/${id}`)`；`PerformanceCycleDetailPage.vue` 增加 9 个 Tab（模板/指标/目标/评价/反馈/校准/结果/申诉/报表），每 Tab 独立六态。本人自评仅显示本人；主管评分仅授权下属；结果 Tab 发后显示 `snapshot/published_at` 且禁用评分；申诉按钮只在 `PUBLISHED` + 结果快照时出现。
- [ ] **Step 4: 删除九个旧页面与映射**
```bash
git rm frontend/src/modules/talent/pages/Performance{Templates,Indicators,Goals,Reviews,Feedback,Calibration,Results,Appeals,Reports}Page.vue
```
从 `frontend/src/router/registry.ts` 删除 9 条 `FOLD`，从 `frontend/src/modules/talent/pages/index.ts` 删除 9 个 key，从 `frontend/src/modules/talent/catalog.ts` 删除 9 个旧 config 或改为详情内部面板（不得保留 registry 映射）；更新 `talentModulePages.spec.ts`/`talentPages.spec.ts` 的 key 与页面数量断言。不得添加 redirect。
- [ ] **Step 5: 残留扫描、运行单测并提交**
Run: `rg -n "performance/(templates|indicators|goals|reviews|feedback|calibration|results|appeals|reports)" frontend/src docs/openapi/openapi.yaml` Expected: 无输出（历史矩阵/计划除外）。Run: `cd frontend && pnpm test:unit -- PerformanceWorkspaceTabs.spec.ts PerformanceWorkspace.spec.ts talentModulePages.spec.ts`；Expected: PASS。
```bash
git add -A frontend docs
git commit -m "refactor(performance): fold legacy pages into cycle detail tabs"
```
### Task 5: 真实栈 E2E、发布冻结与证据
**Files:** Create: `frontend/tests/e2e/real/performance-workspace.spec.ts`（若已有则扩展）; Modify: `frontend/tests/e2e/real/cluster-journeys.spec.ts`（E2E-08）; Create: `docs/implementation/M15/04-evidence.md`
**Interfaces:**
- Consumes: `demo.manager`、`demo.hr`；真实 `POST /v1/performance/cycles/{id}/publish`、`POST /v1/performance/reviews/{id}/appeals`、`GET /v1/performance/results`。; Produces: 发布后结果不可改、未发布不可申诉、已发布可申诉的浏览器/DB 证据。
- [ ] **Step 1: 写失败 E2E（先在 Task 1 前的代码基线运行；执行前标 NOT RUN）**
```ts
test('publish freezes results', async ({ page }) => {
  await login(page, HR_USER); await page.goto(`/performance/cycles/${cycleId}?tab=results`)
  await page.getByTestId('performance-publish').click(); await page.getByTestId('performance-publish-confirm').click()
  await expect(page.getByTestId('performance-result-published')).toContainText('已发布')
  await expect(page.getByTestId('performance-score-31')).toHaveCount(0)
})
```
用例覆盖：周期详情列表/评分真实读写；发布前申诉 409；发布后本人申诉成功；HR 处理申诉；发布后修改评分 409；报表只计已发布；截图/trace。执行：
```bash
cd frontend && HRMS_DEMO_PASSWORD=*** pnpm exec playwright test tests/e2e/real/performance-workspace.spec.ts tests/e2e/real/cluster-journeys.spec.ts --config=playwright.real.config.ts
```
Expected：代码基线首次 FAIL（published snapshot/appeal guard 不存在）；Task 1–4 完成后 PASS + screenshots/trace；计划阶段 `NOT RUN`。
- [ ] **Step 2: 运行失败基线，再跑修复后版本并记录证据**
`docs/implementation/M15/04-evidence.md` 写：命令、退出码、日志、截图、`performance_result_snapshots` 前后行数、发布前后评分/净结果、`performance_appeals` 状态。Run: `pnpm gate:all`，Expected: exit 0（执行后记录）；未执行写 `NOT RUN`。
- [ ] **Step 3: 提交**
```bash
git add frontend/tests/e2e docs/implementation/M15
git commit -m "test(performance): add published result and appeal evidence"
```
## 4. 权限负向测试
| 动作 | 未登录 | 无能力 | 跨 anchor | 字段/导出 | 状态冲突 | 幂等 |
|---|---|---|---|---|---|---|
| 周期创建 | 401 | 403 | ORG 外 403 | 周期字段 P1 | 重复名称 409 | 同键 replay |
| 模板/指标 | 401 | 403 | ORG 外 403 | 模板权重 P1 | 权重≠100/重复 code 409 | 同键 replay |
| 目标保存 | 401 | 403 | 非本人/非下属 404 | 目标/分数 P2 | 过期周期 409 | 同键 replay |
| 自评/主管评分 | 401 | 403 | 非本人/非下属 404 | 分数 P2 | 已提交/已发布 409 | 同键 replay |
| 发布结果 | 401 | 403 | ORG 外 403 | 结果 P2 | 未全提交 409；已发布 409 | 同键 replay |
| 申诉 | 401 | 403 | 非本人/越权 404 | 申诉材料 P2 | 未发布/重复 409 | 同键 replay |
| 结果/报表读取 | 401 | 403 | 越权 404 | 本人仅本人；导出白名单 | 未发布 pending 200 | 只读 |
## 5. 退出检查
- [ ] `/performance` 周期列表 + `/performance/cycles/:id` 详情是唯一绩效入口；9 个 FOLD 路径在 registry、barrel、catalog、definitions、OpenAPI、旧测试无残留。
- [ ] `performance_result_snapshots` 前向迁移/回填通过；发布写快照，发布后 `results` 读快照，`reports` 只统计已发布。
- [ ] 发布仍要求全部 review SUBMITTED；发布后评分/校准/提交 409；既有冻结实现保留，无重写。
- [ ] 申诉必须 cycle PUBLISHED + 存在 result snapshot；未发布 409；同一 review 重复申诉 409；评分不被申诉改写。
- [ ] 本人/主管/HR 的 data scope、SOD、字段 P2、403/404 负测全部覆盖。
- [ ] `/performance/cycles/:id` 详情 9 Tab、六态、中文字典、能力隐藏有 Vitest 覆盖；旧 `cycle_id` query 不再作为深链。
- [ ] 真实 E2E-08 已执行并附日志/截图；未执行写 `NOT RUN`，模块 `BLOCKED`。
- [ ] `pnpm gate:all` 退出码 0；否则不得进入 M16。
- [ ] `docs/implementation/M15/04-evidence.md` 与 `evidence/` 完整，无 Critical/Important 未关闭。
