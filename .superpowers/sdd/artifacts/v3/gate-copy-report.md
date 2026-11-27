# User-copy gate remediation report (v3)

Date: 2026-11-27
Branch: feat/v2.1-remediation
Worktree: D:/code/ai/factoryHR/.worktrees/v2.1-remediation
Task: make `node scripts/audit_user_copy.mjs` exit 0 without changing behaviour, layout or testids.

## Result

- Before: exit 1, 32 failures (English enums, API paths, Wave marker, field-profile codes).
- After: exit 0, `{ "ok": true, "failures": [] }`.
- `node scripts/audit_ui_primitives.mjs`: exit 0.
- `node scripts/audit_page_model.mjs`: exit 0.
- `cd frontend && pnpm typecheck`: exit 0.
- `cd frontend && pnpm lint`: exit 0.
- `cd frontend && pnpm test:unit`: 66 files, 545 tests, all passed.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`: OK (1246 tests, 58376 assertions).

## Dictionary extension

`frontend/src/shared/display/dictionary.ts` now owns the Chinese labels for every affected code and exports `DISPLAY_CODES` so templates can reference protocol codes without showing raw enum literals.

Entries added/expanded:

- `people.employeeStatus`: `LEAVE`, `ON_LEAVE`, `SUSPENDED` added (existing ACTIVE/PROBATION/TERMINATED/ARCHIVED retained, TERMINATED label now 已离职).
- `people.employmentType`: `FIXED_TERM`, `CONTRACTOR` added.
- `people.positionStatus`: `ACTIVE` 启用, `DISABLED` 停用.
- `people.departmentStatus`: `ACTIVE` 启用, `INACTIVE` 停用.
- `people.contractType`: `FIXED_TERM`, `OPEN_ENDED`, `INTERNSHIP`, `CONSULTANT`.
- `people.contractStatus`: `DRAFT`, `ACTIVE`/`EFFECTIVE` 生效中, `EXPIRING`, `EXPIRED`, `RENEWED`, `TERMINATED`, `ARCHIVED`.
- `people.applicationStatus`: DRAFT/SUBMITTED/IN_REVIEW/APPROVED/REJECTED/WITHDRAWN/CANCELLED/SCHEDULED/ACTIVE(借调中)/COMPLETED/PENDING.
- `people.terminationType`: RESIGNATION/TERMINATION/RETIREMENT/CONTRACT_END/MUTUAL_AGREEMENT.
- `platform.userStatus`: ACTIVE/LOCKED/DISABLED.
- `platform.fieldProfile`: P0_PUBLIC/P1_INTERNAL/P2_SENSITIVE/P3_RESTRICTED (P0 公开 / P1 内部 / P2 敏感 / P3 受限).
- `talent.performanceCycleStatus`: DRAFT/ACTIVE(进行中)/SCORING/PUBLISHED.
- `talent.trainingProgramStatus`: ACTIVE(启用中)/ARCHIVED.
- `reporting.reportStatus`: ACTIVE/PROBATION/LEAVE/SUSPENDED/TERMINATED/PENDING/IN_APPROVAL/APPROVED/REJECTED.
- `translate()` accepts an explicit Chinese fallback so unknown codes never render raw (`未知状态`, `未知类型`, `未知字段档位`, `其他状态`).

`frontend/tests/unit/dictionary.spec.ts` extended from 3 to 6 tests covering the added domains and safe fallback.

## Copy changes

- Replaced raw English enum comparisons/options with `DISPLAY_CODES` and dictionary lookups in people components/pages: ContractForm, EmployeeCreateForm, MovementForms, PositionForms, EmployeeCreateDrawer, EmployeeSummary, EmployeeTable, EmployeeTabs, MovementFormDrawer, PositionFormDrawer, PositionTable.
- Removed `Wave 2` breadcrumb marker in PeopleWorkspace.
- Removed API paths from user copy in MovementForms, PositionForms, TrainingWorkspacePage and the platform catalog limitation text; reporting/performance sections now describe the source in Chinese.
- Field-profile/status/user-status labels in RoleActionMatrix, RoleFieldPolicyEditor, UserPermissionPreview now resolve through the shared dictionary instead of per-component maps.
- Operator-facing technical labels in report/dashboard/export surfaces: `as_of` → 截至日期, `source_hash` → 数据版本. Audit/export diagnostics retain literal identifiers as support evidence per lead ruling.
- `platformPages.spec.ts` is mixed: the page-model agent changed registry counts/removed obsolete notice-editor tests, and this task updated two expectations from `P3_RESTRICTED` to `P3 受限` and from `GET /v1/auth/events` to `认证事件与访问复核已接入真实接口`. No test was deleted by this task.

## Verification notes

- Shared files were committed first by the UI/page-model agents; this commit contains only the remaining copy/dictionary work plus the report.
- Staged explicit paths only; no `git add -A`.
- The `.tmp-ui-gate-before.json` scratch file from the UI agent was removed and was never staged.
