# FactoryHR V2.3 M16｜招聘需求与候选人 — 详细实施计划
> **状态：Proposed — 待用户确认。** 本计划不得在规格批准前执行。**For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.
**Goal:** 将 16 个招聘入口收敛为 `/recruitment/requests`、`/recruitment/candidates`、`/recruitment/candidates/:id`，补齐候选人—需求—岗位开放—面试—评价—Offer 的真实关联，并保证 Offer 接受与转入职幂等、绝不创建第二个员工。
**Architecture:** `Talent/Recruitment` 独占需求、岗位开放、候选人、应聘申请、面试、评价、Offer、沟通、人才库与转入职事实；Lifecycle 独占入职案件与员工创建。招聘经 `TalentOnboardingDraftPort` 只传稳定 ID 并读取既有员工，不跨表写员工。前端只保留两个主工作区和一个详情深链，FOLD 子页变为视图、工具栏动作或详情 Tab。
**Tech Stack:** Laravel 13 / PHP 8.3 / MySQL 8.4 / Vue 3.5 / TypeScript / Element Plus / PHPUnit / Vitest / Playwright / OpenAPI 3.1。
## Global Constraints
- 规范优先级：用户裁决 > 本计划 > V2.3 M16 模块规格 > V2.1 设计 > 历史文档；13 个 `FOLD` URL 不做重定向，registry、barrel、catalog、Vue、动作清单与旧测试同时删除。
- 一表一个写入模块；Talent 不直接写 `persons/employments/assignments/lifecycle_intents/cases`；写命令使用 `CommandRunner`/`IdempotencyRunner` + `Idempotency-Key` + `AuditRecorder`，业务幂等键优先。
- 联系方式只返回 `has_email/has_mobile` 或脱敏值；金额整数最小单位；业务日期按公司时区与日历；未执行测试、E2E、迁移演练写 `NOT RUN`。
## 1. 模块事实
| 页面 | 路由 | 当前文件 | 职责 |
|---|---|---|---|
| 招聘需求工作区 | `/recruitment/requests` | `frontend/src/modules/talent/recruiting/pages/RequisitionWorkspacePage.vue`（包装 `frontend/src/modules/talent/pages/RequisitionListPage.vue`） | 列表/创建/编辑/关闭、审批入口、岗位开放、看板、预算、统计 |
| 候选人工作区 | `/recruitment/candidates` | `.../recruiting/pages/CandidateWorkspacePage.vue`（包装 `.../pages/CandidateListPage.vue`） | 列表、流程看板、导入、人才库筛选 |
| 候选人详情 | `/recruitment/candidates/:id` | `.../recruiting/pages/CandidateDetailPage.vue`（包装 `.../pages/CandidateDetailPage.vue`） | 稳定深链；申请/面试/评价/Offer/沟通/标签/转入职 |
`frontend/src/router/registry.ts:246-258` 只保留 `recruitment-requests`、`recruitment-candidates`、`recruitment-candidates-by-id`；详情 `kind: detail`、`menu: null`。
**FOLD 删除与能力目的地**
| # | 原路由 | 删除文件/注册 | 能力目的地 |
|---:|---|---|---|
| 112 | `/recruitment/requests/approval` | `RequisitionApprovalPage.vue`; `recruitment-requests-approval` | `?tab=approval` 显示 `case_id` 并链接 `/workflow/cases/:id` |
| 113 | `/recruitment/requests/openings` | `RequisitionOpeningsPage.vue`; `recruitment-requests-openings` | `?tab=openings`；`GET/POST /v1/recruitment/requisitions/{id}/openings` |
| 114 | `/recruitment/requests/board` | `RequisitionBoardPage.vue`; `recruitment-requests-board` | `?tab=board`；`GET /v1/recruitment/board` |
| 115 | `/recruitment/requests/budget` | `RequisitionBudgetPage.vue`; `recruitment-requests-budget` | `?tab=budget`；`GET /v1/recruitment/budget` |
| 116 | `/recruitment/requests/reports` | `RequisitionReportsPage.vue`; `recruitment-requests-reports` | `?tab=reports` + 报表中心 `RECRUITMENT_FUNNEL`；统计口径归报表中心 |
| 119 | `/recruitment/candidates/imports` | `CandidateImportsPage.vue`; `recruitment-candidates-imports` | 工具栏“导入简历”；`POST /v1/recruitment/candidates/imports` |
| 120 | `/recruitment/candidates/pipeline` | `CandidatePipelinePage.vue`; `recruitment-candidates-pipeline` | `?view=pipeline`；`GET /v1/recruitment/board` + stage 命令 |
| 121 | `/recruitment/candidates/interviews` | `CandidateInterviewsPage.vue`; `recruitment-candidates-interviews` | `?tab=interviews`；`GET/POST .../{id}/interviews` |
| 122 | `/recruitment/candidates/evaluations` | `CandidateEvaluationsPage.vue`; `recruitment-candidates-evaluations` | `?tab=evaluations`；`GET/POST .../{id}/evaluations` |
| 123 | `/recruitment/candidates/offers` | `CandidateOffersPage.vue`; `recruitment-candidates-offers` | `?tab=offers`；`GET/POST /v1/recruitment/offers`、`POST .../{id}/accept` |
| 124 | `/recruitment/candidates/communications` | `CandidateCommunicationsPage.vue`; `recruitment-candidates-communications` | `?tab=communications`；`GET/POST .../{id}/communications` |
| 125 | `/recruitment/candidates/talent-pool` | `CandidateTalentPoolPage.vue`; `recruitment-candidates-talent-pool` | 列表筛选 + `?tab=talent-pool`；`GET/POST .../{id}/talent-pool` |
| 126 | `/recruitment/candidates/convert` | `CandidateConvertPage.vue`; `recruitment-candidates-convert` | `?tab=conversion`；`recruitment.candidate.convert` |
1. `CandidateDetailPage.vue` 只有概览、只读 Offer/面试与转入职弹窗，面试/评价/Offer/沟通/人才库写能力仍在 FOLD 壳中；`RecruitmentController.php::candidates()` 用面试/Offer 的 `requisition_id` 反推需求，跨需求应聘无直接事实表。
2. `RecruitmentService.php::acceptOffer()` 已锁 Offer，但 `TalentOnboardingDraftService.php` 以 JSON `candidate_id+offer_id` 查询且无唯一约束；`RecruitmentFunnelService.php::convertCandidate()` 又直接 `createPerson/createEmployment/createAssignment`，两条路径可建第二个员工。
3. `frontend/src/modules/talent/recruiting/api.ts` 的 `normaliseOffer()` 丢弃 `onboarding_case_id`；详情无法展示“待入职/已入职”。
## 2. 目标契约
```text
/recruitment/requests
├── 列表：组织/状态/关键字、创建、编辑、关闭
├── 详情抽屉：概览 | 审批 | 岗位开放 | 预算与编制
└── 视图：board（进度） | reports（统计；链接报表中心）
/recruitment/candidates
├── 列表：新增、导入简历、需求/状态/关键字、人才库筛选
├── 视图：pipeline（需求看板；拖动只调用 stage 命令）
└── /recruitment/candidates/:id?tab=overview|applications|interviews|evaluations|offers|communications|talent-pool|conversion
```
六态：`loading | empty | unconfigured | forbidden | error | success`；`unconfigured` 用于无已批准需求、无面试或未接受 Offer。技术枚举经 `recruiting/api.ts` 中文字典渲染。
| 操作 | 状态 | Capability | 契约 |
|---|---|---|---|
| `GET/POST /v1/recruitment/requisitions` | 现有 | `recruitment.requisition.read/manage` | 创建进入 Workflow `talent.requisition` |
| `PATCH .../requisitions/{id}`、`POST .../close` | 现有 | `recruitment.requisition.manage` | 关闭后禁止新 Offer/面试 |
| `GET/POST .../requisitions/{id}/openings` | 现有 | `recruitment.requisition.read/manage` | 仅 `APPROVED` 需求；渠道唯一 |
| `GET /v1/recruitment/board\|budget\|reports` | 现有 | `recruitment.board.read`/`recruitment.budget.read` | 只读投影；前端不得估算漏斗 |
| `GET /v1/recruitment/candidates` | 现有，扩展 | `recruitment.candidate.read` | 增加 `application_id/application_stage/onboarding_case_id` |
| `GET/POST .../candidates/{id}/applications` | **NEW** | `recruitment.candidate.read/manage` | `candidate_id+requisition_id` 唯一；`opening_id` 必须同需求 |
| `PATCH .../candidates/{id}/stage` | 现有 | `recruitment.candidate.manage` | 只改申请阶段；禁止手工 `CONVERTED/HIRED` |
| `GET/POST .../candidates/{id}/interviews`、`evaluations` | 现有，修正评价归属 | `recruitment.interview.manage`/`evaluation.manage` | 需求已批准；`interview_id` 必须属于该候选人 |
| `GET/POST /v1/recruitment/offers` | 现有 | `recruitment.offer.manage` | 已批准需求；版本递增；金额整数分 |
| `POST /v1/recruitment/offers/{id}/accept` | 现有，扩展 | `recruitment.offer.accept` | 返回 `onboarding_case_id/offer_status/replayed` |
| `POST .../candidates/{id}/convert` | **语义调整** | `recruitment.candidate.convert` | 未生效 202；生效后登记既有员工，不直接建档 |
| `GET/POST .../{id}/communications`、`talent-pool` | 现有 | `recruitment.communication.manage`/`talent-pool.manage` | 同意依据必填；正文 P2；标签唯一 |
```text
需求：DRAFT -> PENDING_APPROVAL -> APPROVED -> CLOSED；PENDING_APPROVAL -> REJECTED
申请：NEW -> SCREENING -> INTERVIEW -> OFFER -> OFFER_ACCEPTED -> CONVERTED；任意前期 -> REJECTED
Offer：PENDING_APPROVAL -> APPROVED -> ACCEPTED/SUPERSEDED；入职：DRAFT -> SUBMITTED -> APPROVED -> APPLIED；仅 ACCEPTED offer + APPLIED onboarding 才登记 CONVERTED
```
`actor_user_id` 来自 Principal；`subject` 是 `candidate_id/application_id`；审批人只来自 Workflow `assignee_user_id`。前端不得提交 `actor_user_id/tenant_id/legal_entity_id`。
- 进入页：`recruitment.requisition.read`、`recruitment.candidate.read`；越界统一 404。
- 写动作：需求 `manage`、面试 `interview.manage`、评价 `evaluation.manage`、Offer `offer.manage/accept`、沟通 `communication.manage`、人才库 `talent-pool.manage`、转入职 `candidate.convert`。
- 字段：姓名 P1、联系方式 P2、Offer 薪资 P3、面试反馈 P2；列表仅 `has_email/has_mobile`，详情读敏感字段写 `SensitiveAccessAuditor`。
## 3. 任务
### Task 1: 应聘申请事实表与真实关联
**Files:** Create `backend/database/migrations/2026_11_27_000400_create_candidate_applications.php`、`backend/app/Modules/Talent/Models/CandidateApplication.php`；Modify `backend/app/Modules/Talent/Http/Controllers/RecruitmentController.php`、`backend/app/Modules/Talent/Services/RecruitmentService.php`、`backend/routes/api.php`、`docs/openapi/openapi.yaml`、`docs/generated/action-input.json`；Test `backend/tests/Feature/Talent/CandidateApplicationTest.php`。
**Interfaces:**
- Consumes: `candidates`、`recruitment_requisitions`、`requisition_openings`；`PrincipalResolver`、`CommandRunner`、`AuditRecorder`。
- Produces: `candidate_applications(candidate_id,requisition_id,opening_id,stage,status,source,recorded_at)`，唯一 `candidate_id+requisition_id`；`GET/POST .../{id}/applications`；候选人新增 `application_id/application_stage`。
- [ ] **Step 1: 写失败测试**
```php
public function test_one_application_per_requisition_and_opening_must_belong_to_it(): void
{
    $candidate = Candidate::query()->create(['display_name' => '合成申请候选人', 'status' => 'NEW', 'recorded_at' => $this->clock->now()]);
    $org = $this->peopleScenario->orgUnit('APPLICATION-ORG');
    $requisition = Requisition::query()->create(['org_unit_id' => (int) $org->id, 'requisition_no' => 'REQ-APP-1', 'title' => '合成岗位', 'status' => 'APPROVED', 'headcount' => 1, 'recorded_at' => $this->clock->now()]);
    $opening = RequisitionOpening::query()->create(['requisition_id' => (int) $requisition->id, 'channel' => 'EXTERNAL', 'status' => 'OPEN', 'recorded_at' => $this->clock->now()]);
    $first = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/applications", ['requisition_id' => (int) $requisition->id, 'opening_id' => (int) $opening->id], ['Idempotency-Key' => 'application-1'])->assertCreated();
    $this->postJson("/v1/recruitment/candidates/{$candidate->id}/applications", ['requisition_id' => (int) $requisition->id], ['Idempotency-Key' => 'application-2'])->assertOk();
    $this->assertSame(1, CandidateApplication::query()->where('candidate_id', $candidate->id)->count());
    $this->assertSame($first->json('data.application_id'), $this->getJson("/v1/recruitment/candidates/{$candidate->id}/applications")->json('data.items.0.application_id'));
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter CandidateApplicationTest`
Expected: FAIL，表与路由不存在。
- [ ] **Step 3: 写迁移、模型和命令**
```php
Schema::create('candidate_applications', function (Blueprint $table): void {
    $table->id(); $table->unsignedBigInteger('candidate_id')->index(); $table->unsignedBigInteger('requisition_id')->index();
    $table->unsignedBigInteger('opening_id')->nullable()->index(); $table->string('stage', 24)->default('NEW');
    $table->string('status', 24)->default('ACTIVE'); $table->string('source', 32)->nullable();
    $table->dateTime('recorded_at', 6); $table->timestamps();
    $table->unique(['candidate_id', 'requisition_id'], 'candidate_application_unique');
});
DB::table('candidate_applications')->insertUsing(
    ['candidate_id', 'requisition_id', 'stage', 'status', 'recorded_at', 'created_at', 'updated_at'],
    DB::table('interviews')->select('candidate_id', 'requisition_id', DB::raw("'INTERVIEW'"), DB::raw("'ACTIVE'"), 'recorded_at', 'created_at', 'updated_at')
        ->unionAll(DB::table('offers')->select('candidate_id', 'requisition_id', DB::raw("'OFFER'"), DB::raw("'ACTIVE'"), 'recorded_at', 'created_at', 'updated_at'))
);
```
`storeApplication()` 在事务内锁候选人和需求；需求必须 `APPROVED`；`opening_id` 必须属于该需求且 `OPEN`；命中唯一键返回原记录并写 `business_replayed=true`；审计 `talent.candidate_application.created`。OpenAPI 新增 `getCandidateApplications`/`storeCandidateApplication`，`x-capability` 为 `recruitment.candidate.read/manage`；动作 `recruitment.applications.create` 的 `pageCode` 为 `recruitment-candidates-by-id`。
- [ ] **Step 4: 跑测试并提交**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'CandidateApplicationTest|RecruitmentWorkspaceTest|PagePermissionCoverageTest'`
Expected: PASS。
```bash
git add backend/database/migrations backend/app/Modules/Talent backend/routes/api.php backend/tests/Feature/Talent docs/openapi/openapi.yaml docs/generated/action-input.json
git commit -m "feat(recruitment): add candidate applications and true requisition association"
```
### Task 2: Offer 接受—入职草稿—转入职单一路径
**Files:** Create `backend/database/migrations/2026_11_27_000410_add_offer_id_to_lifecycle_intents.php`；Modify `backend/app/Modules/Lifecycle/Models/LifecycleIntent.php`、`.../Services/LifecycleService.php`、`.../Services/TalentOnboardingDraftService.php`、`.../Contracts/TalentOnboardingDraftPort.php`、`backend/app/Modules/Talent/Services/RecruitmentFunnelService.php`、`.../Http/Controllers/RecruitmentFunnelController.php`、`docs/openapi/openapi.yaml`；Test `backend/tests/Feature/Talent/RecruitmentOnboardingIdempotencyTest.php`。
**Interfaces:**
- Consumes: `RecruitmentService::acceptOffer()` 的 Offer 行锁与幂等键；`OnboardingEffectHandler` 的 `result_employment_id`。
- Produces: `lifecycle_intents.offer_id`（`case_type+offer_id` 唯一）；`TalentOnboardingDraftPort::onboardingStateForOffer(int $offerId): array{case_id:int,status:string,employment_id:?int,person_id:?int}`；convert 202 `PENDING_ONBOARDING` 或 201 `CONVERTED`；`candidate_conversions.onboarding_case_id`。
- [ ] **Step 1: 写失败测试**
```php
public function test_accept_and_convert_never_create_a_second_employee(): void
{
    [$candidate, $offerId, $org] = $this->acceptedOfferFixture('ONBOARD-IDEM');
    $accepted = $this->postJson("/v1/recruitment/offers/{$offerId}/accept", ['org_unit_id' => (int) $org->id, 'employee_no' => 'ONB-0001'], ['Idempotency-Key' => 'accept-1'])->assertCreated();
    $caseId = (int) $accepted->json('data.onboarding_case_id');
    $this->assertSame(1, LifecycleIntent::query()->where('offer_id', $offerId)->count());
    $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", ['org_unit_id' => (int) $org->id, 'employee_no' => 'ONB-0001'], ['Idempotency-Key' => 'convert-1'])
        ->assertStatus(202)->assertJsonPath('data.onboarding_case_id', $caseId)->assertJsonPath('data.status', 'PENDING_ONBOARDING');
    $this->assertSame(0, Employment::query()->count());
    $this->completeOnboardingCase($caseId);
    $done = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", ['org_unit_id' => (int) $org->id, 'employee_no' => 'ONB-0001'], ['Idempotency-Key' => 'convert-2'])->assertCreated();
    $employmentId = (int) $done->json('data.employment_id');
    $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", ['org_unit_id' => (int) $org->id, 'employee_no' => 'ONB-0001'], ['Idempotency-Key' => 'convert-3'])->assertOk()->assertJsonPath('data.employment_id', $employmentId);
    $this->assertSame(1, Employment::query()->count());
    $this->assertSame(1, CandidateConversion::query()->where('candidate_id', $candidate->id)->where('onboarding_case_id', $caseId)->count());
}
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter RecruitmentOnboardingIdempotencyTest`
Expected: FAIL；新列不存在，convert 当前直接建员工。
- [ ] **Step 3: 迁移、端口与唯一路径**
```php
Schema::table('lifecycle_intents', function (Blueprint $table): void {
    $table->unsignedBigInteger('offer_id')->nullable()->index();
    $table->unique(['case_type', 'offer_id'], 'lifecycle_intent_offer_unique');
});
Schema::table('candidate_conversions', function (Blueprint $table): void {
    $table->unsignedBigInteger('onboarding_case_id')->nullable()->index();
});
foreach (DB::table('lifecycle_intents')->where('case_type', 'ONBOARDING')->get() as $intent) {
    $payload = json_decode((string) $intent->intent_json, true);
    if (is_array($payload) && isset($payload['offer_id'])) DB::table('lifecycle_intents')->where('id', $intent->id)->update(['offer_id' => (int) $payload['offer_id']]);
}
```
`LifecycleService` 创建 intent 时写 `offer_id`；`TalentOnboardingDraftService` 改为按 `offer_id` 查询，并在唯一键冲突后重读同一行。`RecruitmentFunnelService::convertCandidate()` 删除 `createPerson/createEmployment/createAssignment`：经新端口读 `onboardingStateForOffer()`；未 `APPLIED` 返回 202 `PENDING_ONBOARDING`；`APPLIED` 时只用端口返回的既有 `employment_id/person_id` 写一条 `candidate_conversions` 并将候选人置 `CONVERTED`；重复命中 `candidate_id` 唯一键返回 `business_replayed=true`。历史 Offer 无草稿时先建草稿并返回 202。
- [ ] **Step 4: OpenAPI、负测与提交**
OpenAPI 明确 convert 的 201/202/409；`409 ONBOARDING_NOT_APPLIED` 不得翻译成成功；端口新方法同步到测试 fake。负测：同 Offer 两个键返回同一 `onboarding_case_id`；并发接受只一条 intent；未生效 202 且 `Employment` 为 0；生效后两次 convert 只一条 conversion/employment；无 `recruitment.candidate.convert` 403。
Run: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --filter 'RecruitmentOnboardingIdempotencyTest|RecruitmentToHireTest|OfferIdempotencyTest|RecruitmentFunnelTest'`
Expected: PASS。
```bash
git add backend/database/migrations backend/app/Modules/Lifecycle backend/app/Modules/Talent backend/tests/Feature/Talent backend/tests/Feature/Lifecycle docs/openapi/openapi.yaml
git commit -m "fix(recruitment): make offer acceptance the only path to a new employee"
```
### Task 3: 两个工作区视图
**Files:** Modify `frontend/src/modules/talent/recruiting/pages/RequisitionWorkspacePage.vue`、`CandidateWorkspacePage.vue`、`.../api.ts`、`.../components/CandidatePipeline.vue`、`frontend/src/router/registry.ts`、`frontend/src/modules/talent/pages/index.ts`；Create `.../components/RequisitionDetailTabs.vue`、`RequisitionBoardPanel.vue`、`RequisitionBudgetPanel.vue`、`RequisitionReportsPanel.vue`、`CandidateImportDrawer.vue`；Test `frontend/tests/unit/RecruitmentWorkspaceTabs.spec.ts`。
**Interfaces:**
- Consumes: Task 1 申请 API、现有 `listRequisitions/listRequisitionBoard/listRequisitionBudget/listCandidates/moveCandidateStage/importCandidates`；`PageShell/DataTable/ElDrawer/ElTabs`。
- Produces: `/recruitment/requests?tab=overview|approval|openings|budget|board|reports`；`/recruitment/candidates?view=list|pipeline`；test ids `requisition-detail-tabs`、`requisition-approval-case-link`、`candidate-import-open`、`candidate-pipeline-board`。
- [ ] **Step 1: 写失败测试**
```ts
it('renders one requisition workspace with tabs and a workflow case link', async () => {
  const wrapper = await mountAt(RequisitionWorkspacePage, '/recruitment/requests?tab=approval')
  expect(wrapper.get('[data-testid="requisition-detail-tabs"]').exists()).toBe(true)
  expect(wrapper.get('[data-testid="requisition-approval-case-link"]').attributes('href')).toBe('/workflow/cases/9')
})
it('opens the candidate import drawer', async () => {
  const wrapper = await mountAt(CandidateWorkspacePage, '/recruitment/candidates')
  await wrapper.get('[data-testid="candidate-import-open"]').trigger('click')
  expect(wrapper.find('[data-testid="candidate-import-drawer"]').exists()).toBe(true)
})
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd frontend && pnpm vitest run tests/unit/RecruitmentWorkspaceTabs.spec.ts`
Expected: FAIL，当前无 Tab 与导入 Drawer。
- [ ] **Step 3: 实现工作区**
`RequisitionWorkspacePage` 行点击打开 `RequisitionDetailTabs`；审批 Tab 只读显示 `case_id/status` 并链接 `/workflow/cases/:id`；岗位开放调用 `GET/POST .../{id}/openings`；看板 `GET /v1/recruitment/board`；预算 `GET /v1/recruitment/budget`，金额 `formatMoney`；统计 `GET /v1/recruitment/reports` 并链接 `/reports?type=RECRUITMENT_FUNNEL`。`CandidateWorkspacePage` 加视图切换、导入 Drawer、人才库筛选；看板拖动只调用 `PATCH .../{id}/stage`，不做乐观伪造。每个 Tab 独立六态。
- [ ] **Step 4: 更新 registry 并提交**
`registry.ts` 三个保留页的 `requiredApis` 加入 openings/board/budget/reports/import/applications/interviews/evaluations/offers/communications/talent-pool/convert 的真实操作；`pages/index.ts` 保留三个 key，删除 13 个 FOLD key。
Run: `cd frontend && pnpm vitest run tests/unit/RecruitmentWorkspaceTabs.spec.ts tests/unit/RecruitmentWorkspace.spec.ts`
Expected: PASS。
```bash
git add frontend/src/modules/talent/recruiting frontend/src/modules/talent/pages/index.ts frontend/src/router/registry.ts frontend/tests/unit/RecruitmentWorkspaceTabs.spec.ts
git commit -m "feat(recruitment): fold subpages into two workspaces"
```
### Task 4: 候选人详情八 Tab、删除 13 个旧页
**Files:** Modify `frontend/src/modules/talent/recruiting/pages/CandidateDetailPage.vue`、`.../api.ts`、`registry.ts`、`pages/index.ts`、`frontend/src/modules/talent/catalog.ts`、`docs/generated/action-input.json`、`frontend/tests/unit/talentPages.spec.ts`、`talentModulePages.spec.ts`、`routeRegistry.spec.ts`、`scripts/lib/clusterMembership.mjs`；Create `.../components/Candidate{Applications,Interviews,Evaluations,Offers,Communications,TalentPool,Conversion}Panel.vue`；Delete 13 个 FOLD Vue 文件；Test `frontend/tests/unit/CandidateDetailTabs.spec.ts`、`recruitmentFoldDeletion.spec.ts`。
**Interfaces:**
- Consumes: 现有 interviews/evaluations/communications/talent-pool/offers/accept、Task 1 applications、Task 2 conversion；Offer 投影新增 `onboarding_case_id`。
- Produces: `?tab=overview|applications|interviews|evaluations|offers|communications|talent-pool|conversion`；写弹窗成功刷新、失败保留输入；Offer 接受显示 `/workflow/cases/:id`；完成转入职显示 `/hr/employees/:id`。
- [ ] **Step 1: 写失败测试**
```ts
it('creates an interview through the real endpoint', async () => {
  const wrapper = await mountDetail('/recruitment/candidates/21?tab=interviews')
  await wrapper.get('[data-testid="interview-create"]').trigger('click')
  await wrapper.get('[data-testid="interview-interviewer"]').setValue('合成面试官')
  await wrapper.get('[data-testid="interview-submit"]').trigger('click')
  expect(resource.post).toHaveBeenCalledWith('/v1/recruitment/candidates/21/interviews', expect.objectContaining({ interviewer: '合成面试官' }), expect.objectContaining({ idempotencyKey: expect.any(String) }))
})
it('shows the onboarding case instead of an employee while conversion is pending', async () => {
  resource.post.mockResolvedValue({ status: 'PENDING_ONBOARDING', onboarding_case_id: 44 })
  const wrapper = await mountDetail('/recruitment/candidates/21?tab=conversion')
  await wrapper.get('[data-testid="conversion-submit"]').trigger('click')
  expect(wrapper.get('[data-testid="conversion-onboarding-link"]').attributes('href')).toBe('/workflow/cases/44')
  expect(wrapper.find('[data-testid="conversion-employee-link"]').exists()).toBe(false)
})
```
- [ ] **Step 2: 运行并确认失败**
Run: `cd frontend && pnpm vitest run tests/unit/CandidateDetailTabs.spec.ts tests/unit/recruitmentFoldDeletion.spec.ts`
Expected: FAIL，详情无写 Tab 且 13 条旧路径仍在 registry。
- [ ] **Step 3: 实现详情并删除旧页**
`api.ts` 的 `normaliseOffer()` 增加 `onboardingCaseId`，`normaliseCandidate()` 增加 `conversionStatus/onboardingCaseId`；新增 `listApplications/createApplication/createInterview/createEvaluation/createOffer/acceptOffer/createCommunication/createTalentPoolTag`，全部从真实 envelope 解析。详情按 capability 隐藏动作；Offer 金额 `formatMoney`；沟通正文 P2；转换 202 显示待入职案件、201 显示员工链接，重试复用同一幂等键。
```bash
git rm frontend/src/modules/talent/pages/Requisition{Approval,Openings,Board,Budget,Reports}Page.vue
git rm frontend/src/modules/talent/pages/Candidate{Imports,Pipeline,Interviews,Evaluations,Offers,Communications,TalentPool,Convert}Page.vue
```
从 registry、barrel、catalog 删除 13 个 key；动作迁移到三个保留页并运行 `pnpm actions:generate`。`talentPages.spec.ts` 的 35 改为实算 22；`clusterMembership.mjs` 删除 13 个 key 并更新 K9 计数；`routeRegistry.spec.ts` 使用删除前计数减 13。禁止 redirect、旧壳或仅隐藏菜单。
- [ ] **Step 4: 残留扫描、门禁、提交**
Run: `rg -n "recruitment/(requests/(approval|openings|board|budget|reports)|candidates/(imports|pipeline|interviews|evaluations|offers|communications|talent-pool|convert))" frontend/src scripts frontend/tests docs/generated`
Expected: 无输出。
Run: `pnpm actions:generate && pnpm actions:check && pnpm page-components:check && cd frontend && pnpm vitest run tests/unit/CandidateDetailTabs.spec.ts tests/unit/recruitmentFoldDeletion.spec.ts`
Expected: PASS。
```bash
git add -A frontend scripts docs/generated
git commit -m "refactor(recruitment): delete folded pages and expose detail tabs"
```
### Task 5: 真实栈 E2E 与证据
**Files:** Modify `frontend/tests/e2e/real/recruitment-workspace.spec.ts`、`cluster-journeys.spec.ts`；Create `docs/implementation/M16/04-evidence.md`、`docs/implementation/M16/evidence/`。
**Interfaces:** Consumes `demo.hr`/`demo.manager` 与真实需求、申请、Offer、入职、转换；Produces 浏览器证据、数据库前后计数、截图/trace。
- [ ] **Step 1: 写失败 E2E**
```ts
test('acceptance and conversion never create a second employee', async ({ page }) => {
  await login(page, HR_USER)
  await page.goto(`/recruitment/candidates/${candidateId}?tab=offers`, { waitUntil: 'load' })
  await page.getByTestId('offer-accept').click()
  await expect(page.getByTestId('offer-onboarding-case')).toBeVisible()
  await page.goto(`/recruitment/candidates/${candidateId}?tab=conversion`, { waitUntil: 'load' })
  await page.getByTestId('conversion-submit').click()
  await expect(page.getByTestId('conversion-onboarding-link')).toBeVisible()
})
```
覆盖：关闭需求后 Offer 409；非指派审批人案件 404；两次 convert 只有一条 conversion；`demo.manager` 打开 `/recruitment/requests/approval` 404；截图/trace 存 `evidence/e2e/`。计划阶段状态 `NOT RUN`。
- [ ] **Step 2: 运行真实栈**
```bash
cd backend && php artisan migrate:fresh --seed && php artisan serve --port=8000
cd frontend && HRMS_DEMO_PASSWORD="$HRMS_DEMO_PASSWORD" pnpm exec playwright test tests/e2e/real/recruitment-workspace.spec.ts tests/e2e/real/cluster-journeys.spec.ts --config=playwright.real.config.ts
```
Expected: `NOT RUN`（本计划未执行）；执行后必须 exit 0，否则写 FAILED 并阻断。
- [ ] **Step 3: 记录并提交**
`04-evidence.md` 写命令、退出码、日志、截图、`candidate_applications/lifecycle_intents/candidate_conversions/employments` 前后行数；未执行写 `NOT RUN`。
```bash
git add frontend/tests/e2e/real docs/implementation/M16
git commit -m "test(recruitment): add workspace and onboarding idempotency evidence"
```
## 4. 权限负向测试
| 动作 | 未登录 | 无能力 | 跨范围 | 字段/导出 | 状态冲突 | 幂等 |
|---|---|---|---|---|---|---|
| 需求创建/编辑/关闭、岗位开放 | 401 | 403 | 非公司/未批准 403/422 | 预算/渠道 P2 | 已关闭或重复渠道 409 | 同键/业务 replay |
| 候选人读取/申请 | 401 | 403 | 越界 404 | 联系方式 P2 | 重复申请 200 | 唯一键 |
| 面试排期/评价 | 401 | 403 | 未批准或非本人面试 422 | 反馈/评分 P2 | 冲突或非面试官 409/403 | 同键 replay |
| Offer 创建/接受 | 401 | 403 | 越界 404 | 薪资 P3 | 关闭需求 409 | 同 Offer 同案件 |
| 转入职 | 401 | 403 | 越界 404 | 薪资 P3 | 未 APPLIED 202/409 | 只一个员工 |
| 沟通/人才库/看板/预算/统计 | 401 | 403 | 越界 403/404 | 正文 P2；导出白名单 | 标签唯一 | 同键 replay/只读 |
## 5. 退出检查
- [ ] `/recruitment/requests`、`/recruitment/candidates` 是唯一侧栏入口，`/recruitment/candidates/:id` 是唯一详情深链；13 个 FOLD 路由在 registry、barrel、catalog、Vue、动作清单、cluster membership、旧测试、导航中无残留，无 redirect。
- [ ] `candidate_applications` 唯一键与候选人—需求—岗位开放—面试—评价—Offer 关联可追溯；`lifecycle_intents.offer_id` 唯一键、Offer 接受 replay、convert 202/201 已落地，任何路径不建第二个员工。
- [ ] 详情八 Tab 有真实读 API、按能力写动作、六态与中文文案，OpenAPI/capability/动作/E2E 一致（`pnpm actions:check`）；真实 E2E 附日志/截图，未执行写 `NOT RUN`，模块 `BLOCKED`。`pnpm gate:all` 退出码 0，否则不得进入 M17。
