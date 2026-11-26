# Volume 3 Task 14 — 招聘需求与候选人工作台报告

日期：2026-11-27（Asia/Singapore）  
分支：`feat/v2.1-remediation`  
提交主题：`feat(talent): build the recruitment workspace`

## 状态

**DONE_WITH_CONCERNS**

交付了招聘需求工作台、候选人管道、候选人详情与录用转入职，以及 `RecruitmentController` 的缺失工作台方法。后端 Feature 测试、后端全量测试、Pint、PHPStan、capability audit、arch:test 均通过。
前端聚焦单测与 E2E 清单通过；全量前端 typecheck/lint/unit 当前仅被并发的平台工作台（`WorkflowCaseDetailPage.vue` / `platformWorkspaces.spec.ts`）阻塞，本任务文件无错误。

## 交付文件

| 文件 | 内容 |
|---|---|
| `frontend/src/modules/talent/recruiting/api.ts` | 真实路由适配、金额保持整数最小单位、状态/阶段中文映射、`Idempotency-Key` 生成、转换请求体只含 `org_unit_id` + `employee_no` + 可选 `hire_date` |
| `frontend/src/modules/talent/recruiting/pages/RequisitionWorkspacePage.vue` | 需求列表（岗位、组织单元、编制、状态、开启日期、整数分预算）、创建/编辑抽屉、关闭二次确认、五态、能力守卫 |
| `frontend/src/modules/talent/recruiting/pages/CandidateWorkspacePage.vue` | 六阶段管道（新简历/筛选/面试/offer/已入职/已淘汰）、按需求筛选、显式移动与二次确认、五态、能力守卫 |
| `frontend/src/modules/talent/recruiting/pages/CandidateDetailPage.vue` | 候选人资料、面试记录、Offer 信息、录用转入职对话框、重复员工编号中文提示、成功后展示员工编号与员工详情链接 |
| `frontend/src/modules/talent/recruiting/components/RequisitionFormDrawer.vue` | 创建/编辑共用抽屉；本地校验编制、整数分预算、币种、目标到岗日期；422 字段错误就地显示 |
| `frontend/src/modules/talent/recruiting/components/CandidatePipeline.vue` | 六列真实管道；无数据列明确显示“暂无候选人”；已入职列展示真实 `employee_no`；只允许手工移动到可移动阶段 |
| `backend/app/Modules/Talent/Http/Controllers/RecruitmentController.php` | 补齐 `updateRequisition`、`closeRequisition`、`moveCandidateStage`、`interviews`；增强 `requisitions`/`candidates` 投影（组织名称、开启日期、转换事实、按需求筛选、分页）；面试反馈按 `recruitment.evaluation.manage` 做字段级裁剪并记录敏感读取审计 |
| `backend/tests/Feature/Talent/RecruitmentWorkspaceTest.php` | 9 tests / 76 assertions：需求创建与投影、编辑/关闭幂等、阶段移动、按需求筛选、转换无 `legal_entity_id`、重复员工编号 409 回滚、面试反馈字段权限、转换与读取未授权 403 |
| `frontend/tests/unit/RecruitmentWorkspace.spec.ts` | 13 tests：需求五态/中文状态/金额格式化/创建/编辑/关闭确认；管道六阶段/筛选/移动确认/失败保持；详情资料/面试/Offer/转换安全/未找到 |
| `frontend/tests/e2e/real/recruitment-workspace.spec.ts` | 5 Playwright tests（真实栈、无 demo 密码时 skip；不伪造行） |

## 关键契约

- 转换调用 `POST /v1/recruitment/candidates/{id}/convert`，请求体仅 `org_unit_id`、`employee_no`、可选 `hire_date`；从不发送 `tenant_id`、`legal_entity_id`。单测直接断言请求体键集合。
- 转换使用 `Idempotency-Key`；后端既有 `CandidateConversion` 作为业务幂等键：同一候选人新幂等键重放返回原 `employment_id`，不会创建第二个 Person/Employment。
- 重复 `employee_no` 由 People writer 抛 `409 / EMPLOYEE_NO_DUPLICATE`；事务回滚（Person 数不变、无 conversion 行、candidate 未链接人员），前端中文提示“员工编号已存在”并保持对话框与输入。
- 所有金额为整数最小单位（分），仅在 `formatMoney()` 渲染时格式化；缺失金额不显示 `¥0.00`。
- 前端能力守卫：`recruitment.requisition.read/manage`、`recruitment.candidate.read/manage`、`recruitment.candidate.convert`、`recruitment.offer.manage`、`recruitment.evaluation.manage`；无能力时不发请求。
- 已入职不可由通用阶段移动伪造；`moveCandidateStage` 只接受 NEW/SCREENING/INTERVIEW/OFFER/OFFER_ACCEPTED/REJECTED，`CONVERTED` 返回 422 `INVALID_CANDIDATE_STAGE`。

## 缺失路由 / 契约变更（按约束只报告，未改 `routes/api.php` / OpenAPI）

生产路由 `php artisan route:list --path=recruitment` 仍为 21 条。以下方法已实现，但必须在路由所有者处接线：

1. `PATCH /v1/recruitment/requisitions/{id}` → `RecruitmentController@updateRequisition`，能力 `recruitment.requisition.manage,COMPANY`。
2. `POST /v1/recruitment/requisitions/{id}/close` → `RecruitmentController@closeRequisition`，能力 `recruitment.requisition.manage,COMPANY`。
3. `PATCH /v1/recruitment/candidates/{id}/stage` → `RecruitmentController@moveCandidateStage`，能力 `recruitment.candidate.manage`。
4. `GET /v1/recruitment/candidates/{id}/interviews` → `RecruitmentController@interviews`，能力 `recruitment.candidate.read`（反馈字段另需 `recruitment.evaluation.manage`）。
5. `GET /v1/recruitment/candidates` 当前指向 `RecruitmentFunnelController@candidates`，该投影不接收 `requisition_id`，也不返回 `requisition_id`/转换事实。需将该路由改指 `RecruitmentController@candidates`（能力不变）；否则管道页只能展示全量候选人，按需求筛选不生效。
6. OpenAPI 需要补充上述 4 个新操作、候选列表查询参数（`requisition_id`、`status`、`search`、`page`、`page_size`）、需求列表查询参数与新增字段（`org_unit_name`、`opened_at`、`target_start_date`、`budget_minor` 等），并为 `PATCH` 操作添加 `x-capability` 与 `x-action-label`。
7. 页面接线（禁止修改处）：`frontend/src/modules/talent/pages/index.ts` / `frontend/src/router` 仍映射旧 `recruitment/requests/index`、`recruitment/candidates/index`、`recruitment/candidates/by-id`。需由集成所有者将这三个 key 指向 `modules/talent/recruiting/pages/*`；`CandidateWorkspacePage` 建议替换 `recruitment/candidates/pipeline` 或 `recruitment/candidates/index` 的组件。

## 验证证据（本会话实际执行）

| 命令 | 结果 |
|---|---|
| `phpunit --filter='RecruitmentWorkspaceTest'` | **OK (9 tests, 76 assertions)** |
| `phpunit`（全量） | **OK (1244 tests, 58227 assertions)**，耗时 2:04 |
| `pint --test`（全量） | **PASS 1190 files** |
| `phpstan analyse --no-progress`（全量） | **[OK] No errors** |
| `php artisan capability:audit` | `Capability audit OK`（public allowlist 6） |
| `php artisan arch:test` | `Module boundaries OK`，4 条既有非阻断 WARN |
| `vitest run tests/unit/RecruitmentWorkspace.spec.ts` | **13/13 passed** |
| `pnpm test:unit`（全量） | **530 passed / 9 failed**，全部在并发平台工作台 `platformWorkspaces.spec.ts` / `platformModulePages.spec.ts`；招聘聚焦文件通过 |
| `pnpm typecheck` | 失败 1 处：并发 `src/modules/platform/pages/WorkflowCaseDetailPage.vue` 未使用变量；招聘文件无错误 |
| `pnpm lint` | 失败 1 处：同上并发平台文件；招聘聚焦 lint exit 0 |
| Playwright `--list recruitment-workspace.spec.ts` | **5 tests in 1 file** |
| 招聘相关后端回归 `RecruitmentFunnelTest|RecruitmentToHireTest|OfferIdempotencyTest` | **OK (11 tests, 99 assertions)** |

## TDD 证据

1. 先写 `RecruitmentWorkspaceTest`，聚焦运行得到 5 个失败：`moveCandidateStage` / `interviews` 未定义、候选筛选未生效、问题即“功能缺失”。
2. 实现控制器方法后聚焦运行：`OK (8 tests, 73 assertions)`；随后补充生产转换路由的未授权测试，`OK (9 tests, 76 assertions)`。
3. 先写 `RecruitmentWorkspace.spec.ts`，运行失败于页面文件不存在（Vite 无法解析 import）。
4. 实现页面/组件后逐步转绿：需求抽屉原生 `type=number` 输入与提交事件、候选人筛选异步加载、转换请求体键集合均被测试驱动修正，最终 `13/13`。

## 已知限制

- 真实 E2E 套件需要 `HRMS_DEMO_PASSWORD` 与已 seed 的 demo 租户；本次按任务要求只执行 `--list` 5 条，未在无凭据环境伪造执行结果。
- 由于路由未接线，新工作台在当前分支的生产 URL 上尚不可达；上述路由与页面映射是发布前必做项。
- 候选人详情复用 `GET /v1/recruitment/candidates` 的列表投影按 id 定位（无 `GET /candidates/{id}` 路由），在数据量较大时应增加详情路由；本任务未新增。
- Offer 详情依赖既有 `GET /v1/recruitment/offers?candidate_id=`，金额字段为 P3 敏感，页面在缺少 `recruitment.offer.manage` 时不读取并以中文提示。
