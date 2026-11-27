# Volume 3 Task 13 — 绩效考核工作台报告

日期：2026-11-27（Asia/Singapore）
分支：`feat/v2.1-remediation`
提交：`feat(talent): build the performance workspace`（见最终提交 SHA）

## 1. 交付文件

| 类型 | 文件 |
|---|---|
| 前端页面 | `frontend/src/modules/talent/performance/pages/PerformanceWorkspacePage.vue` |
| 前端页面 | `frontend/src/modules/talent/performance/pages/PerformanceCycleDetailPage.vue` |
| 前端组件 | `frontend/src/modules/talent/performance/components/PerformanceGoalTable.vue` |
| 前端组件 | `frontend/src/modules/talent/performance/components/PerformanceScoreDrawer.vue` |
| 前端 API | `frontend/src/modules/talent/performance/api.ts` |
| 后端控制器 | `backend/app/Modules/Talent/Http/Controllers/PerformanceController.php`（仅缺失的列表/评分读写能力） |
| 后端测试 | `backend/tests/Feature/Talent/PerformanceWorkspaceTest.php` |
| 前端单测 | `frontend/tests/unit/PerformanceWorkspace.spec.ts` |
| 前端 E2E | `frontend/tests/e2e/real/performance-workspace.spec.ts` |

未修改 `frontend/src/router/**`、`frontend/src/modules/talent/pages/index.ts`、`backend/routes/api.php`、`docs/openapi/**`，也未修改任何 training/recruitment 文件。

## 2. 真实路由核对

编码前执行 `php artisan route:list --path=performance`。实际可用的绩效路由：

- `GET/POST /v1/performance/cycles`
- `POST /v1/performance/cycles/{id}/publish`
- `GET/POST /v1/performance/reviews`
- `POST /v1/performance/reviews/{id}/submit`
- `POST /v1/performance/reviews/{id}/calibration`
- `POST /v1/performance/reviews/{id}/appeals`
- `GET/POST /v1/performance/templates|indicators|goals|feedback|calibration|results|reports`

**没有** `GET /v1/performance/cycles/{id}`，也没有 review PATCH 路由。因此：

- 周期详情通过已注册的 `/performance?cycle_id={id}` 打开，页面从真实 `GET /v1/performance/cycles` 分页投影中定位该周期，绝不拼接不存在的详情 URL。
- 评分保存复用唯一的真实写路由 `POST /v1/performance/reviews`，`employment_id` 放在 body（不是路径），并由后端把同一 cycle/employment 的 DRAFT 保存做成幂等的 create-or-save。
- 提交、校准、申诉、发布分别调用真实的 `/submit`、`/calibration`、`/appeals`、`/cycles/{id}/publish`。

## 3. 需求实现

### 周期列表
- 名称、考核期间、状态（草稿/进行中/评分中/已结束）、参与人数、已提交人数、进度百分比。
- 服务端分页（`page`、`page_size`，最大 100）、名称筛选、状态筛选；进度由服务端聚合 `已提交评估 / 参与人数`，客户端不用假数据补行。
- 状态映射同时识别底层 `DRAFT/ACTIVE/SCORING/PUBLISHED`；`ACTIVE` 有提交记录时展示为“评分中”，否则“进行中”。
- 服务端若返回 `partial` 标记，页面进入 partial 状态展示提示，不用 0 或推测值补全。

### 周期详情
- 员工/主管行、评估状态、目标列表与权重合计、自评分数、主管分数、服务端最终等级。
- 已提交或已发布行只读；`评分`按钮只在 DRAFT 且周期未发布时出现，其他状态通过“查看”打开只读评分抽屉。
- 校准、申诉为独立动作：校准对话框只提交 `calibrated_rating + reason`；申诉对话框只提交 `reason`，两者都不能改写原始分数。
- 发布动作有二次确认，并说明发布后全部评估只读；仍在 DRAFT 的评估会阻止发布。

### 目标表与权重校验
- 列：目标标题、权重（整数百分比）、目标值、自评分数、主管分数。
- 逐项权重必须为 1..100 的整数，运行合计必须等于 100；页面实时显示 `当前 / 100` 并阻止保存。
- 后端在 `POST /v1/performance/reviews` 再次校验：范围错误走 Laravel 字段错误；合计错误返回 `INVALID_WEIGHT_TOTAL` 与 `weight_total`，不落任何 review 行。

### 评分抽屉
- 分数全程按字符串传输，不经过 `parseFloat`/浮点运算；整数和最多 6 位小数的十进制字符串都可原样提交。
- 最终等级只显示后端 `final_rating` 投影（校准等级优先，否则已提交评估的主管等级），编辑主管分数不会让页面“算出”最终等级。
- 中文等级标签：`EXCEEDS=超出预期`、`MEETS=符合预期`、`BELOW=低于预期` 等；未知值原样显示为 `<值> 分`。
- 已决定评估显示只读徽标，所有输入 disabled，保存/提交按钮禁用且不会发请求。

### 五态与能力
- 五态：loading、empty、error、forbidden、partial，均有真实测试覆盖。
- 能力守卫：`performance.cycle.read`（页面/列表）、`performance.review.manage`（评估行与评分保存）、`performance.review.submit`（提交）、`performance.calibration.manage`、`performance.appeal.manage`、`performance.result.publish`。
- 缺少能力时不发起对应的真实读取/写入请求；服务端仍会再次校验能力、锚点与状态。

## 4. 后端安全与范围

- 列表先解析调用者能力，再用 `Authorizer::constrain(PerformanceReview::query(), ..., 'performance.cycle.read')` 限制周期可见范围：只有存在可见评估行的周期才对 SELF/MANAGED 用户可见；COMPANY 范围（HR）仍可看到尚无评估的周期。
- 周期聚合只统计被授权约束后的 review 行，避免通过人数推测越权数据。
- `POST /reviews` 在创建/保存前用真实 People 目录校验 `employment_id`，允许 COMPANY，或本人 SELF，或直接下属 MANAGED（`managedEmploymentIds`），或 ORG/ORG_SUBTREE；否则 403，且不落行。`manager_employment_id` 未提供时由真实上下级关系派生。
- 已决定 review：再次保存返回 409 `REVIEW_DECIDED`；已发布周期：返回 409 `CYCLE_PUBLISHED`；提交不是 DRAFT 时同样 409，不覆盖 manager_rating。
- 未经认证的 cycles/reviews 读写请求均返回 401。

## 5. 测试证据（本次会话实际执行）

| 命令 | 结果 |
|---|---|
| `phpunit --filter='PerformanceWorkspaceTest'` | `OK (11 tests, 68 assertions)` |
| `phpunit` 全量 | `OK (1246 tests, 58335 assertions)` |
| `vendor/bin/pint --test` | PASS（1190 files；并行编辑期间 routes/api.php、endpoint_matrix.php 偶发 line_ending，未触碰） |
| `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` |
| `php artisan capability:audit` | `Capability audit OK` |
| `php artisan arch:test` | `Module boundaries OK`（4 条既有 WARN） |
| `node deploy/scripts/openapi-lint.mjs` | `OK (259 paths, 334 operations, 3.1.0)`（含并行模块新增契约） |
| `pnpm openapi:validate` | valid（2 条既有 warning） |
| `pnpm exec vitest run tests/unit/PerformanceWorkspace.spec.ts` | `21 tests passed` |
| `pnpm typecheck` | PASS（全量通过） |
| `pnpm lint` | PASS（`--max-warnings=0`） |
| `pnpm test:unit` | `66 files / 544 tests passed` |
| Playwright `--list` | 4 tests in 1 file |
| `pnpm page-components:check` | `failures: []`；registry 162，mapped 156 + 6 base |
| `node scripts/audit_route_coverage.mjs` | 并行编辑期间因新增 `/workflow/cases/:id` 未进入冻结清单而 `ok: false`（非本任务范围）；此前基线为 `ok: true` |

## 6. 缺失路由 / 契约缺口（未修改，需 lead 处理）

1. **缺少 `GET /v1/performance/cycles/{id}`**：详情页目前只能从列表投影定位周期。若需稳定深链与独立权限，建议新增该只读端点及 OpenAPI。
2. **缺少 review 目标分数的持久化列/端点**：现有 `performance_goals` 只有 title/target/weight/status，没有 `self_score`/`manager_score`。本任务把 per-goal 分数原样存于既有 `goals_text` JSON 证据字段，并在响应中回放；若产品要求按目标独立查询/排序/统计分数，应新增迁移列与 OpenAPI schema（不应在客户端伪造）。
3. **缺少独立的 review PATCH**：为不新增路由，`POST /reviews` 被实现为 DRAFT 的 create-or-save；长期建议提供显式 PATCH `/reviews/{id}` 并保持当前幂等语义。
4. **缺少 `/performance/cycles/:id` 前端注册路由**：按要求未编辑 router；当前通过 `/performance?cycle_id=` 进入详情，若 lead 接受，建议在 registry 增加 detail 路由并指向 `PerformanceCycleDetailPage.vue`。
5. **需要补 OpenAPI（本任务按限制未改）**：
   - `GET /v1/performance/cycles`：增加 `status/q/page/page_size` 查询参数与 `cycle_id/name/period_from/period_to/status/participant_count/submitted_count/draft_count/progress` 列表响应 schema；
   - `POST /v1/performance/reviews`：声明 `employment_id` body、`manager_*` 字段、`goals[]`（整数 1..100 且合计 100）、`self_score/manager_score` 字符串，以及 409 `REVIEW_DECIDED`；
   - `POST /v1/performance/reviews/{id}/submit`：补充 `final_rating` 服务端投影字段；
   - `PerformanceGoal`：把 `weight` 下限改为 1 并增加 `self_score/manager_score`；
   - `frontend/src/generated/api.ts` 需在 lead 允许修改 OpenAPI 后重新生成。

## 7. 并发隔离

未暂存/提交任何并行文件：`routes/api.php`、`endpoint_matrix.php`、`frontend/src/modules/platform/**`、`frontend/src/router/**`、training/recruitment 目录及测试。提交仅包含本报告第 1 节列出的显式路径。
