# ADR-0031: 平台内核归属与依赖方向

- **状态：** Proposed — 2026-11-25
- **关联：** ADR-0001（模块化单体）、ADR-0009（文件与隐私）、ADR-0028
- **复审触发：** 任一业务模块需要平台表；平台需要业务数据；新增平台能力

## 背景

字典、参数、通知投递、文件密级、菜单展示覆盖、日历发布、报表订阅、运维任务目前分散或缺失。若各域自行建表，会违反 one-writer，并让“菜单/字典/通知”出现多份真相。

## 决定

1. 平台内核拥有：`sys_dictionaries`、`sys_parameters`、`notification_templates`、`notification_deliveries`、`notification_outbox`、`file_objects`、`file_access_logs`、`scheduler_runs`、`backup_records`、`menu_overrides`、`report_subscriptions`、`report_exports`。
2. 业务模块只通过 Contracts 使用平台能力；平台不得反向依赖业务模块，不得读取业务表。
3. 菜单展示覆盖（`menu_overrides`）只控制“显示/排序/图标/启停”，不改变 `PAGE_REGISTRY` 的页面事实与能力码；未知 `page_code` 拒绝写入。
4. 字典码稳定、删除前校验引用；参数按租户与作用域分层，敏感参数加密存储。
5. 通知走 Outbox：业务事务写 outbox，投递器幂等消费，失败记录并可重试；投递日志可按 `request_id` 追溯。
6. 文件下载必须签名 URL 或受权代理，禁止公开静态 URL；每次下载写 `file_access_logs`。

## 后果

- 12 个集群复用同一套平台能力，不再各自造配置/通知/文件表。
- 平台迁移由 Platform 模块拥有；业务模块不得直接写这些表。
- 菜单覆盖不影响路由与权限，避免“后台隐藏菜单即绕过权限”的误解。

## 验证

- `ModuleOwnershipTest`：业务模块不写平台表；平台不引用业务模型。
- `MenuOverrideTest`：未知 page_code 拒绝；隐藏菜单后直接访问 URL 仍按能力判定。
- `NotificationOutboxTest`：重复事件不重复投递；失败可重试。
