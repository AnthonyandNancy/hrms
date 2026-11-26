# ADR-0035: Page, View, Action and Permission Boundaries

- Status: Accepted — 2026-11-26
- Date: 2026-11-26
- Approval: 用户 2026-11-26 批准 FactoryHR V2.1 全量整改规格与四册计划；本 ADR 为该批准的可执行化
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
8. Page visibility = `role_pages` grant ∧ page required capability, while action execution requires a separately granted action capability + anchor + field profile.

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
