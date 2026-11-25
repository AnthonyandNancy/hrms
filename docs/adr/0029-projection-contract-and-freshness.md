# ADR-0029: 统一投影契约与新鲜度

- **状态：** Proposed — 2026-11-25
- **关联：** ADR-0028、ADR-0011（部署可观测）、ADR-0026（页面注册表）
- **复审触发：** 任一投影需要跨模块 JOIN；新鲜度 SLA 调整；新增物化投影

## 背景

当前读端点有三套形状：`ApiResponse::list`、报表 `{runId,columns,rows,...}`、看板 `{metrics,...}`。前端为兼容写了 `rowsFrom/recordFrom/partialFlag` 多种适配。报表与看板口径曾出现分叉风险：`PAYROLL_COST`/`RECRUITMENT_FUNNEL` 因无投影直接 503，而页面只能显示“未发布”。

## 决定

1. 所有**新增**读端点返回统一 envelope：

```json
{
  "items": [],
  "page": 1,
  "page_size": 20,
  "total": 0,
  "as_of": "2026-11-25",
  "freshness": "LIVE|MATERIALIZED|STALE",
  "source_hash": "sha256",
  "partial": false,
  "partial_reason": null,
  "drilldown": null
}
```

2. 单对象读端点返回对象本身；列表/投影一律使用上述结构。报表保留 `columns/chart` 扩展字段，但 `items/total/as_of/freshness/source_hash` 必须存在。
3. 投影分两类：
   - `LIVE`：直接由模块 Contract 查询，允许 `as_of`，不落投影表。
   - `MATERIALIZED`：由 `ProjectionBuilder` 重建，带 `source_hash` 与 `freshness`；`STALE` 仅在投影缺行且重建失败时出现，必须带 `partial_reason`。
4. 报表、看板、列表、下钻必须共用同一 `MetricQueryBuilder` 谓词；禁止为报表另写 SQL。
5. 导出复用同一投影与字段白名单；导出任务记录 `source_hash`，支持对账。
6. 前端统一消费 `items/page/total/freshness/partial`；不再为每个域写形状猜测。

## 后果

- `ResourcePage` 与四个域工作台可删除兼容分支。
- 报表 503 从“未发布”变成结构化 `freshness=STALE + partial_reason`；语义 503 保留给真正的依赖不可用。
- 物化投影必须可重建；新增投影需声明重建命令与 SLA。

## 验证

- `ProjectionContractTest`：所有新增读端点字段齐全、`source_hash` 稳定、`partial` 语义正确。
- `ReportDashboardConsistencyTest`：同一 metric 的看板总数与下钻行数一致。
- 160 页审计允许 `STALE` 但页面必须渲染“数据未发布/部分数据”状态，不允许错误边界。
