# M18 集成交接

本目录记录 M18 公告与站内通知的实现范围、待主代理执行的删除/接线动作，以及未运行的验证项。

## 本分支已完成

- 公告受众支持 `ALL` / `ORG` / `ROLE`，发布前使用实时受众预览。
- 公告详情统一为五个标签页：内容、审批、受众、阅读记录、模板。
- 公告审批严格经由 Workflow Case；公告控制器不直接批准自身。
- 阅读记录区分公告确认台账与站内通知已读状态，导出仅允许白名单字段并写敏感访问审计。
- 提醒仅发送给未确认的当前受众，使用 `notice_reminders` 唯一行防重，通知载荷仅含 `notice_id` 与 `requires_ack`。
- 公告受保护写操作统一通过 `CommandRunner`，携带 `Idempotency-Key` 并写审计。
- 站内通知标记已读保持既有接口契约，按 `read_at IS NULL` 幂等，并记录首次状态变更审计。
- 四个 FOLD 页面能力已并入公告工作台；页面文件删除按 `integration.json` 由主代理执行。

## 主代理必须完成的接线

1. `backend/routes/api.php` 新增：
   - `POST /v1/engagement/notices/audience-preview` → `NoticeController@previewAudience`
   - `POST /v1/engagement/notices/{notice}/remind` → `NoticeGovernanceController@remind`
   - 静态路径需置于 `/notices/{notice}` 之前。
2. `docs/openapi/openapi.yaml` 补充上述两个操作，以及阅读统计扩展字段。
3. 生成物重新生成：`docs/generated/action-input.json`、`docs/generated/page-actions.json`、`frontend/src/generated/pageActions.ts`。
4. 删除四个 FOLD Vue 文件，并清理 IAM 页面目录中的对应 page_code。
5. 在 `backend/app/Shared/Architecture/ModuleBoundaryScanner.php` 的 Engagement 表清单加入 `notice_reminders`。

## 验证状态

- `php -l`：通过（所有改动的 PHP 文件）。
- 前端范围 `vue-tsc --noEmit`：通过（M18 组件、页面、测试及既有 `ReportingWorkspace.spec.ts`）。
- 后端 Feature 测试：**NOT RUN**。
- 前端 Vitest：**NOT RUN**。
- `pnpm gate:all`、migrations、Playwright：**NOT RUN**。

详细机器可读清单见 [integration.json](./integration.json)。
