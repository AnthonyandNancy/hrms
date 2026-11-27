# Volume 3 Task 7 — Employee Movement Workspace Report

## Status

**Implemented and committed.** The workspace, detail projection, create drawer,
timeline component, API boundary, backend feature test, unit test and real E2E
spec are staged explicitly.

## Commit

`bed01fa7de94734d6a48149d4d9114110fb3d346` — `feat(lifecycle): build the employee movement workspace`

Staged paths only (explicit `git add -- <paths>`):

- `frontend/src/modules/people/lifecycle/pages/MovementWorkspacePage.vue` (new)
- `frontend/src/modules/people/lifecycle/pages/MovementDetailPage.vue` (new)
- `frontend/src/modules/people/lifecycle/components/MovementFormDrawer.vue` (new)
- `frontend/src/modules/people/lifecycle/components/MovementTimeline.vue` (new)
- `frontend/src/modules/people/lifecycle/api.ts` (new)
- `backend/tests/Feature/Lifecycle/MovementWorkspaceTest.php` (new)
- `frontend/tests/unit/MovementWorkspace.spec.ts` (new)
- `frontend/tests/e2e/real/movement-workspace.spec.ts` (new)

## What was built

1. **Workspace** lists real workflow cases by type (入职/转正/调岗/调部门/调薪/
   借调/离职), filters by type, status and free text, and paginates the merged
   projection. It reads `/v1/cases` per movement type because the workflow list
   has no multi-type filter, reads `/v1/lifecycle/secondments` for parallel
   secondment rows, and never manufactures rows. When the caller has
   `lifecycle.case.read` but not `workflow.case.read`, it uses the lifecycle
   projection and explicitly marks name/effective-date gaps as partial.

2. **Create drawer** supports all six movement kinds with capability-gated
   kinds. Type-specific fields:
   - Onboarding: person, employee number, org, hire date, terms.
   - Probation: employment and effective date.
   - Transfer: target org unit, optional position/manager, effective date.
   - Salary: new amount in **integer minor units**, currency, scheme, reason.
   - Secondment: host org unit, start/end dates, reason.
   - Offboarding: last working date, termination type, reason code, final payroll.
   Client-side validation includes date ordering (secondment end after start,
   probation end after hire, effective date after employment start). Server 422
   field errors render inline under the fields.

3. **Detail page** shows the object summary (employee, case, initiator, target
   org/new salary, reason), a prominent effective-date banner, and the immutable
   workflow timeline from `GET /v1/cases/{id}/timeline` with actor and
   timestamp. Pending approvals show the approve/reject action guarded by
   `workflow.case.decide`; decided cases render a read-only notice and no
   decision controls.

4. **Money rule** — amounts are parsed and transported as integer minor units;
   decimal/sign input is rejected client-side; formatting uses a
   BigInt-based formatter with no float conversion.

5. **Effective-dated semantics** — the effective date is the dominant banner;
   copy states approval is a precondition and the change is not immediate.
   Salary values in the detail are additionally gated by `lifecycle.terms.manage`.

6. **States** — loading, empty, error (with request id + retry), forbidden and
   partial are all explicit. No mock rows, no embedded department/position/
   contract editors; a note directs users to those modules.

7. **Backend** — the controller already contained every method the real routes
   need (`cases`, `showCase`, `onboarding`, `probationConfirmation`, `transfer`,
   `salaryChange`, `offboarding`). No method was added because none was
   genuinely missing. Secondment date ordering is already enforced by
   `SecondmentService` with `SECONDMENT_INVALID_RANGE`; the feature test pins it.

## Verification

- TDD: `MovementWorkspaceTest` was written first and run; the frontend unit
  spec was written before the implementation and observed failing on missing
  modules.
- Backend focused: **4 tests, 43 assertions** OK
  (`vendor/bin/phpunit --filter=MovementWorkspaceTest`).
- Backend full suite: **1222 tests, 58046 assertions** OK.
- Backend Pint on owned + lifecycle paths: **PASS**. Repository-wide Pint has
  one unrelated concurrent edit in `app/Modules/Payroll/Http/Controllers/PayrollRunController.php`.
- Backend PHPStan on owned lifecycle paths: **no errors**. Repository-wide
  PHPStan reports two errors in an unrelated concurrent
  `tests/Feature/Payroll/PayrollWorkspaceTest.php`.
- Frontend typecheck: owned lifecycle files and spec are clean; the repository
  run currently fails in an unrelated concurrent
  `tests/unit/PayrollWorkspace.spec.ts`.
- Frontend lint on owned files: **exit 0**. Repository lint currently fails in
  unrelated concurrent `src/modules/payroll/components/PayrollRunTable.vue`.
- Frontend unit: focused **12/12**; full suite **466/467** with the single
  failure in unrelated concurrent `tests/unit/ContractWorkspace.spec.ts`.
- Playwright list: **2 tests / 1 file**.

## Missing routes / contract gaps (reported, not added)

1. `GET /v1/cases` has no multi-type (`type[]`) filter, so the workspace issues
   one read per movement type. Server-side type/status pagination across all
   movements would require a route/contract change.
2. `GET /v1/lifecycle/cases` returns `effective_at` (effect application time),
   not the planned business effective date; the UI does not relabel it and
   marks that projection as partial.
3. `GET /v1/lifecycle/secondments` has no detail-by-assignment route. The
   detail page reuses the list projection filtered by `assignment_id`.
4. There is no lifecycle route exposing workflow timeline/decisions. The UI
   uses the existing workflow routes `GET /v1/cases/{id}`,
   `GET /v1/cases/{id}/timeline` and `POST /v1/cases/{caseId}/decisions`
   (the latter requires `workflow.case.decide`; in the current role templates only
   LINE_MANAGER holds it, so HR_ADMIN sees the pending timeline note but no
   approve/reject controls).
5. The lifecycle detail projection does not expose actor display names; the
   UI resolves them from `GET /v1/users` when `iam.user.read` is present and
   otherwise shows `用户 #id`, never a fabricated name.
6. Salary change creation requires an approved case in Payroll's adjustment
   endpoint; this workspace creates the case only and does not write
   compensation rows.

## Router integration gap (outside owned files)

`frontend/src/router/pageComponents.ts` / `frontend/src/modules/people/pages/index.ts`
still point `hr/movements/index` and `hr/movements/by-id` at the legacy
`PeopleWorkspace` wrappers. Wiring `MovementWorkspacePage.vue` and
`MovementDetailPage.vue` requires an edit to those forbidden files; the
integration owner must add the loader mapping.
