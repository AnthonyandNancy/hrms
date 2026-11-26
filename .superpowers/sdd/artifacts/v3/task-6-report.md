# Volume 3 Task 6 — Position Workspace Report

## Status

**Implemented and committed.** The six owned frontend files are staged and
committed with no backend, OpenAPI, router, or other page edits. Focused vitest
is 10/10; the full frontend unit suite is 436/436; task-scoped typecheck and
lint are clean. Full-repository typecheck/lint currently fail only in
concurrently edited files outside this task.

## Commit

`1b55cb8aada5a9c2c7a7bfeadb7752b51ac2d024` — `feat(org): build the position workspace`

Staged paths only (explicit `git add -- <paths>`):

- `frontend/src/modules/people/positions/pages/PositionWorkspacePage.vue` (new)
- `frontend/src/modules/people/positions/components/PositionTable.vue` (new)
- `frontend/src/modules/people/positions/components/PositionFormDrawer.vue` (new)
- `frontend/src/modules/people/positions/components/PositionRequirementsPanel.vue` (new)
- `frontend/src/modules/people/positions/api.ts` (new)
- `frontend/tests/unit/PositionWorkspace.spec.ts` (new)

## What was built

1. **Position list** (`PositionWorkspacePage.vue`, `PositionTable.vue`)
   - Columns: 岗位编码, 岗位名称, 部门, 职级, 编制（核定 / 在岗 / 空缺）, 状态,
     编制差异, 操作.
   - `GET /v1/positions` is called with only its real `as_of` and optional `q`
     parameters. The live controller validates only those two and returns all
     matching rows, so status/organisation filtering and pagination are applied
     over the returned server rows. No `status`, `org_unit_id`, `page`,
     `page_size`, or `sort` parameter is invented.
   - Status is rendered in Chinese (启用/停用/未知状态); grade shows the
     catalogue's Chinese name plus code (`专业五级（P5）`), or the raw code when
     the catalogue is unreadable.
   - Organisation names are resolved from `GET /v1/departments`; a failed
     lookup renders a labelled `组织 #id`, never a fake name.
   - `partial` warnings from the server are rendered via the shared warning alert.

2. **Create/edit drawer** (`PositionFormDrawer.vue`)
   - Create sends `POST /v1/positions` with code, title, org unit, grade,
     headcount limit and effective dates.
   - Edit sends `PATCH /v1/positions/{id}` with only the fields the live
     contract accepts: title, grade, headcount_limit, status. Code, org unit and
     the effective range are visibly read-only in edit mode with an explicit
     note that the live PATCH has no versioned catalogue command for them.
   - Half-open range is validated client-side (`失效日期` must be later than
     `生效日期`) before any request; the message explains `[生效日, 失效日)`.
   - Server 422 field errors map inline on title, headcount and code, with
     request id; the drawer stays open.
   - Duplicate code is caught locally against the loaded list and also from a
     server 409 conflict, rendered in Chinese as
     `岗位编码「…」已存在，请使用其他编码。` without leaking the raw reason.
   - Idempotency-Key is generated per submission through the shared helper.

3. **Requirements panel** (`PositionRequirementsPanel.vue`)
   - Reads `GET /v1/positions/{id}/requirements` with `as_of`, `page=1`,
     `page_size=100`; shows the latest version, history and Chinese field
     labels for 技能要求 / 资格要求.
   - The editor keeps a baseline copy of each row. Save submits **only changed
     rows** in the `requirements` object, with the chosen `effective_from`;
     unchanged rows are omitted (verified in the test: only `skills` travelled
     while the baseline `qualifications` stayed out).
   - After success it refetches the requirement projection and renders the new
     version; failures stay inline.
   - The panel is unavailable without `org.position.qualification.manage`, with
     an explicit Chinese capability note instead of a silent read.

4. **Headcount variance warning**
   - List rows and the detail panel show a warning when occupancy exceeds the
     headcount limit, naming the excess and directing personnel changes through
     the employee movement flow.
   - The edit drawer previews the same variance and does **not** disable save;
     a lower limit is allowed to be submitted.

5. **Five states, enums and capability guards**
   - Loading skeleton, empty state with create CTA, error state with shared
     Chinese copy and request id, forbidden state without issuing any read when
     `org.position.read` is absent, and partial-data warning.
   - `org.position.read` gates the page; `org.position.manage` gates 新增岗位
     and 编辑岗位; `org.position.qualification.manage` gates requirement editing.
   - No legal-entity/tenant concepts in the UI.

## TDD evidence

- `frontend/tests/unit/PositionWorkspace.spec.ts` was written first (10 tests).
- Observed RED: Vitest failed to resolve
  `@/modules/people/positions/pages/PositionWorkspacePage.vue`.
- Implemented the production files and iterated to green. A second RED was
  observed for generic create-conflict mapping after the initial suite passed;
  the duplicate-code test was tightened to use only a 409 status (no invented
  reason code) and the form gained the Chinese mapping.

Coverage:

1. Chinese status/grade, org unit, approved/filled/vacancy headcount and over-limit warning, plus the exact server query shape.
2. Server `as_of`/`q` plus client status and org-unit filters; no sort parameter, and partial warning.
3. Server-row pagination and reset to page one on filter change.
4. Half-open range local validation and inline 422 field mapping.
5. Local duplicate-code message and server 409 duplicate message in Chinese.
6. Edit payload restricted to PATCH-supported fields, with a visible read-only note.
7. Requirements changed-rows-only payload and refetch of the new version.
8. Over-limit warning in detail and non-blocking low headcount save.
9. Forbidden state without data requests and read-only capability hiding.
10. Loading, retryable Chinese error with request id, and empty recovery.

## Verification (real output)

- `cd frontend && pnpm exec vitest run tests/unit/PositionWorkspace.spec.ts`
  → **10 passed / 10** (post-commit run also 10/10).
- `cd frontend && pnpm test:unit`
  → **58 files / 436 tests passed** on the final run. An earlier run had a
  single unrelated 5 s timeout in `platformModulePages.spec.ts`; the rerun was
  fully green.
- `cd frontend && pnpm typecheck`
  → **exit 0** on the final run (task-scope `positions`/`PositionWorkspace`
  errors: none). Concurrent edits briefly produced errors in
  `src/modules/reporting/**` and `tests/unit/ReportingWorkspace.spec.ts`.
- `cd frontend && pnpm lint`
  → task-scope lint clean
  (`eslint src/modules/people/positions tests/unit/PositionWorkspace.spec.ts --max-warnings=0`).
  Full lint at the time of writing failed only in concurrent
  `src/modules/time/overtime/**` files (`OvertimeRecordTable.vue`,
  `OvertimeRequestDrawer.vue`, `OvertimeTravelWorkspacePage.vue`).
- `cd backend && ../.tools/php/php.exe artisan route:list --path=positions`
  → 9 routes confirmed (index, store, show, patch, grades GET/POST,
  requirements GET/POST, staff GET).
- Backend test suite was not run because no backend file was changed.

## Endpoint audit / deviations

Live routes used, all verified against `route:list` and OpenAPI:

- `GET /v1/positions` — supports **only** `as_of`, `q`; no page/page_size/status/sort.
- `POST /v1/positions` — code, title, org_unit_id, grade, headcount_limit,
  valid_from, valid_to.
- `PATCH /v1/positions/{id}` — title, grade, headcount_limit, status.
- `GET /v1/positions/{id}/requirements` — as_of, page, page_size.
- `POST /v1/positions/{id}/requirements` — requirements object + effective_from.
- `GET /v1/positions/grades` — as_of, active, page, page_size.
- `GET /v1/departments` — used for the org-unit lookup by name.

Needed-but-absent APIs reported precisely, not faked in the UI:

1. **`GET /v1/positions` pagination/filter/sort contract** — the brief asks for
   filters and pagination. The live endpoint exposes neither; the page therefore
   filters and pages client-side over the as-of/query result and tells the
   operator so. Server-side paging would require a backend + OpenAPI change.
2. **Versioned catalogue edit for code / org unit / effective range** — PATCH
   has no such fields. The drawer displays them read-only and states the gap;
   nothing is silently dropped or locally persisted.
3. **Per-row requirement PATCH / upsert** — only append-version POST exists.
   "Only changed rows" is implemented by diffing against the latest version and
   sending a new version containing only those changed rows; no PATCH is invented.
4. **Router wiring** — `frontend/src/router/registry.ts` is forbidden for this
   task. `/hr/positions` still resolves the existing `hr/positions/index`
   config page; the integration owner must point that key at
   `PositionWorkspacePage.vue`.
5. **Explicit duplicate-code reason** — the live `PositionService::open` does
   not pre-check unique codes, so a duplicate may surface as a generic 409
   (or, depending on driver, a 500). The form maps a create 409 to the Chinese
   duplicate message; a dedicated `POSITION_CODE_DUPLICATE` reason would require
   the backend change that is out of this task's scope.

## Constraints respected

- Only the six owned paths were created and committed; no `git add -A`.
- No backend, `docs/openapi/**`, router registry, or other page file edited.
- No mock adapter rows; empty responses result in an explicit empty state.
- Module boundary unit test passes with the new `positions` module.
