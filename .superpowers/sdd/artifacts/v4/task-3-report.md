# Volume 4 Task 3 — Workflow center consistency confirmation

## Status

**Verified with two real gaps fixed.** The Volume 3 Task 17 workflow center and
case detail already existed; no duplicate center or components were created.
The plan's `modules/platform/workflow/pages/WorkflowCenterPage.vue`,
`components/TaskList.vue` and `components/CaseTimeline.vue` paths do not exist
and were not created (`path-alignment.md` wins). This is a recorded deviation
from the plan's file list.

Commit: `test(workflow): confirm the workflow center matches its contract`

## Paths (actual)

- `frontend/src/modules/platform/pages/WorkflowCenterPage.vue` — key `workflow`
- `frontend/src/modules/platform/workflow/pages/WorkflowCaseDetailPage.vue` — key `workflow/cases/by-id`
- `frontend/src/modules/platform/api/index.ts` — workflow client (extended)
- `backend/app/Modules/Workflow/Services/CaseQueryService.php` — list/detail read model
- `backend/tests/Feature/Workflow/WorkflowCenterTest.php` — new
- `frontend/tests/unit/WorkflowCenter.spec.ts` — new
- `frontend/tests/e2e/real/workflow-center.spec.ts` — new (collected, not executed)

## Endpoints verified with `php artisan route:list`

`--path=cases`:
- `GET /v1/cases` → `workflow.cases.index`, `capability:workflow.case.read`
- `GET /v1/cases/{id}` → `workflow.cases.show`, `capability:workflow.case.read`
- `GET /v1/cases/{id}/timeline` → `workflow.cases.timeline`, `capability:workflow.case.read`
- `POST /v1/cases/{caseId}/decisions` → `workflow.cases.decide`, `capability:workflow.case.decide`
- `POST /v1/cases/{id}/submit|withdraw`, `POST /v1/cases` (not changed)

`--path=workflow`:
- `GET/POST /v1/workflow/templates` (template management, not used by the center)

Task list: `GET /v1/tasks/my` → `capability:workflow.case.read`, pending tasks
assigned to the caller only, with `sla_state`.

## Recorded limitations (not fixed; genuine contract gaps)

1. `GET /v1/cases` has **no multi-type (`type[]`) filter**. It supports a single
   `type`. The lifecycle movement workspace already compensates by issuing one
   bounded query per type (`.superpowers/sdd/artifacts/v3/task-7-report.md`
   gaps 1–2; requested backlog record was not present in
   `.superpowers/sdd/artifacts/v3/backlog.md`, which only lists gaps 1–9 for
   other modules). Adding a multi-type contract was out of scope for this
   consistency task.
2. Lifecycle projection carries an **effect timestamp** (`effective_at`, the
   effect application time), not a planned business effective date. The
   workflow center itself displays `business_date` and does not relabel effect
   timestamps; this is documented in the V3 Task 7 report.

## Gaps found and fixed (minimal)

### Gap 1 — "已办 / my decided" view was missing

Plan Step 2 requires 待办/已办/我发起. The center only had
`my_approvals` / `my_requests` / all, and `CaseQueryService` only understood
those two view values. A decided case disappeared from 待办 with no place to
see it.

Fix:
- `CaseQueryService::list` accepts `view=my_decided` and filters cases where
  the caller is a decision actor (includes delegated/admin-override records).
- `CaseQueryService::visibleQuery` grants read visibility to a decision actor,
  so a delegated approver keeps access to the case they decided. No new route
  was needed; this is an extension of the existing E3 list route.
- Frontend client type `WorkflowCaseView` extended; the center renders a fourth
  view `我审批的` with an honest empty state and no task-projection join
  (SLA values only belong to the pending view).
- OpenAPI list parameters (`view`, `status`, `effect_status`, `type`,
  `business_date`) documented, including the "no multi-value type" rule.
- Endpoint matrix row `GET /v1/cases` gained `out_of_scope_empty`.

### Gap 2 — Decision action and contract inconsistencies

- Detail page rendered the "当前待办决定" panel for an assigned non-holder of
  `workflow.case.decide`, showing a disabled/muted body. The requirement is to
  **offer** a decision only when both conditions hold, so the panel is now
  `v-if="awaitingMe && canDecide"`; non-holders see the read-only explanation.
- Center type chips used stale codes (`TRANSFER_REQUEST`,
  `CONTRACT_RENEWAL`, `ONBOARDING` only) while real modules emit
  `ASSIGNMENT_CHANGE`, `CONTRACT_RENEW`, `REQUISITION_APPROVAL`,
  `OFFER_APPROVAL`, `PERFORMANCE_APPEAL`. Those filters could never match a
  real row. A closed `WORKFLOW_CASE_TYPE_OPTIONS` catalog is now the single
  client source, and labels cover the real codes.
- Current node rendered as generic "审批处理中"; a shared `workflowNodeLabel`
  now maps real node keys (`start`, `manager_approval`, `hr_review`,
  `approval`, `approve`) and never echoes raw keys.
- Decision OpenAPI falsely advertised a `201` response, made
  `Idempotency-Key` optional, and left `action` as a free string. It now
  requires the header, documents the enum, replay/409/404 semantics, and has
  only the real `200` response. Endpoint matrix row gained `assignee_only` and
  `decided_case_conflict` denials.

The backend guards were already real and remain unchanged:
- `DecisionService::resolveActingGrant` requires a pending task for the current
  node/assignee (delegation or admin override are explicit alternatives) and
  throws `NotFoundException` (404) otherwise, preventing existence leaks.
- `WorkflowStateMachine::canDecide` rejects non-open cases with `ConflictException`
  (409) before any decision row is written.
- `CaseQueryService::visibleQuery` scopes lists/details to requester, task
  assignee or decision actor; unrelated callers get 404 on detail.
- Decisions are append-only (model-level update/delete throws); idempotent replay
  returns the stored 200 body with `Idempotency-Replayed: true`.

## Tests

### `backend/tests/Feature/Workflow/WorkflowCenterTest.php` (8 tests, 73 assertions)

1. Assignee sees the pending task in `/v1/tasks/my` and in `view=my_approvals`.
2. Decided case leaves `my_approvals`, appears in `my_decided`; another user
   sees an empty `my_decided`.
3. A visible requester who is not the assignee gets 404 on decision and writes
   no decision row (real backend guard, not a disabled button).
4. Caller without `workflow.case.decide` gets 403 and writes no decision.
5. Repeated same decision replays with `Idempotency-Replayed: true`; a later
   conflicting decision on a decided case is 409 and keeps one decision row.
6. A decision creates a timeline `DECISION` entry with actor + timestamp plus
   `decision.recorded` and `case.approved` events.
7. A decided case stays readable, reports `is_awaiting_me=false`, and still
   appears in the personal task projection with zero pending tasks.
8. A completely out-of-scope non-assignee gets 404 on detail, zero rows in both
   list views and cannot decide.

The plan mentioned `Tests\Support\WorkflowCenterScenario`; no such support class
exists. The established `WorkflowTestHelpers` trait was reused instead of
adding a parallel fixture abstraction (deviation, minimal surface).

### `frontend/tests/unit/WorkflowCenter.spec.ts` (9 tests)

Covers: real type catalog (no fabricated filters), four views and real query
parameters, honest empty states, timeline actor/timestamp + Chinese node
labels, decision offered only to assignee with capability, decided case
read-only, no list request without read capability, client `effect_status` /
`business_date` parameters, and contract rejection of a non-integer actor id.

### `frontend/tests/e2e/real/workflow-center.spec.ts` (2 tests, collected only)

`pnpm exec playwright test --config=playwright.real.config.ts --list tests/e2e/real/workflow-center.spec.ts`
→ **2 tests listed**. Real execution needs the seeded stack plus
`HRMS_DEMO_PASSWORD`; **NOT EXECUTED** in this worktree, as allowed by the
parallel plan. The spec verifies the list/detail/timeline responses, actor and
timestamp rendering, read-only decided cases, and backend refusal (403/404/409)
of non-assignee or repeat decisions.

## Gates (real output)

Owned/affected checks:
- `phpunit --filter=WorkflowCenterTest` → **OK (8 tests, 73 assertions)**
- `phpunit --filter='WorkflowCenterTest|AuthorizationTest|StatusSeparationTest|DecisionImmutabilityTest'`
  → **OK (31 tests, 201 assertions)**
- `phpunit --filter='WorkflowCenterTest|GapCompletionCapabilityMatrixTest|CapabilityAuditCommandTest'`
  → **OK (21 tests, 96 assertions)**
- `pint --test` on owned PHP + workflow test + matrix → **PASS**
- `phpstan analyse --no-progress` → **OK, no errors**
- `artisan capability:audit` → **Capability audit OK** (4 warnings for the
  concurrent Task 2 account-session routes, not mine)
- `artisan arch:test` → **Module boundaries OK** (4 pre-existing non-blocking
  dynamic-table warnings)
- `node deploy/scripts/openapi-lint.mjs` → **OK (259 paths, 334 operations, 3.1.0)**
- `pnpm openapi:validate` → **valid**, 2 pre-existing health-route warnings
- `pnpm openapi:generate` → regenerated `frontend/src/generated/api.ts`; staged
  spec and staged generated file verified byte-identical
- frontend `pnpm exec eslint` on all owned files → **clean, 0 warnings**
- frontend `pnpm exec vitest run tests/unit/WorkflowCenter.spec.ts tests/unit/platformWorkspaces.spec.ts`
  → **23/23 passed**
- Playwright real collection → **2 tests in 1 file**

Concurrent worktree state (not ours, reported rather than fixed):
- Full backend suite (latest run: 1281 tests, 58,738 assertions): **2 failures**
  in contract tests for Task 2's new `/v1/auth/sessions` / `/v1/auth/mfa/status`
  routes, which were mid-flight at the time of the run (matrix rows on only one
  of the two IAM manifests). Our `WorkflowCenterTest` and all workflow contract
  tests pass.
- Full frontend unit suite (71 files, 589 tests): **6 failures** in
  `SettingsWorkspace.spec.ts` (Task 5) and `platformModulePages.spec.ts`
  (`account` page not yet in `DEDICATED_PAGES`, Task 2). All workflow specs
  pass.
- Repository-wide `pnpm typecheck`/`pnpm lint` currently fail on the concurrent
  `frontend/src/modules/platform/catalog.ts` unused `NOTICE_DESCRIPTION`
  (Task 1/Task 6). Owned files are clean.
- Repository-wide `pint --test` currently flags concurrent
  `AuthController.php`, `routes/api.php`, `ScratchSessionTest.php`.
- `AGENTS.md`/`package.json` (`gate:all`) were changed concurrently and were not
  staged by this task.

## Recorded limitations (genuine gaps, not fixed)

1. `GET /v1/cases` has **no multi-type (`type[]`) filter**. It supports a single
   `type`. The lifecycle movement workspace already compensates by issuing one
   bounded query per type (V3 Task 7 report gaps 1–2). Adding a multi-type
   contract was out of scope for this consistency task.
2. Lifecycle projection carries an **effect timestamp** (`effective_at`, the
   effect application time), not a planned business effective date. The
   workflow center displays `business_date` and does not relabel effect
   timestamps; documented in the V3 Task 7 report.

## Loader / registry edits needed

**None.** No page was added or removed. `workflow` and `workflow/cases/by-id`
already exist in `router/registry.ts`, `modules/platform/pages/index.ts`,
`router/pageComponents.ts` and all pinned-count tests. No count changes.

## Deviations from the plan's file list

1. Center lives at `modules/platform/pages/WorkflowCenterPage.vue` (path
   alignment), not `modules/platform/workflow/pages/`.
2. `TaskList.vue` / `CaseTimeline.vue` were not created; the timeline is inline
   and there was no genuine need to split it.
3. `CaseController` / `DecisionController` were **not modified**: every method
   the real routes call already existed, and the assignee/state/immutability
   guards are in services that were already correct. The only backend change is
   the `view=my_decided` read filter in `CaseQueryService`.
4. `WorkflowCenterTest` uses `Tests\Support\WorkflowTestHelpers` instead of the
   nonexistent `WorkflowCenterScenario`.
5. `frontend/tests/unit/WorkflowCenter.spec.ts` was added (required by the
   brief; not in the plan's file list).
