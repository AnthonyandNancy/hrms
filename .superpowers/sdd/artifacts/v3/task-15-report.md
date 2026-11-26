# Volume 3 Task 15 — Training workspace report

## Status

Implemented. Backend workspace projection + completion guard and the four
frontend training files are complete and covered by 4 backend feature tests
and 12 vitest tests. Two real backend gaps are reported, not faked:

1. `POST /v1/training/programs/{id}/publish` and
   `POST /v1/training/programs/{id}/close` do not exist.
2. `PATCH /v1/training/programs/{id}` (edit) and
   `POST /v1/training/enrollments/{id}/cancel` do not exist.

The UI keeps the real confirmation dialogs and then states the exact missing
route; it never changes local status. No performance/recruiting file was
touched.

## Commit

`feat(talent): build the training workspace` — staged explicit paths only:

- `frontend/src/modules/talent/training/api.ts` (new)
- `frontend/src/modules/talent/training/pages/TrainingWorkspacePage.vue` (new)
- `frontend/src/modules/talent/training/pages/TrainingProgramDetailPage.vue` (new)
- `frontend/src/modules/talent/training/components/TrainingProgramDrawer.vue` (new)
- `frontend/src/modules/talent/training/components/TrainingEnrollmentTable.vue` (new)
- `backend/app/Modules/Talent/Http/Controllers/TrainingController.php`
- `backend/app/Modules/Talent/Services/TrainingService.php`
- `backend/tests/Feature/Talent/TrainingWorkspaceTest.php` (new)
- `frontend/tests/unit/TrainingWorkspace.spec.ts` (new)
- `frontend/tests/e2e/real/training-workspace.spec.ts` (new)
- `.superpowers/sdd/artifacts/v3/task-15-report.md` (this file)

`TrainingService.php` is included because the active-enrolment completion
guard and business-date `completed_on` handling are required behaviour from the
brief; the controller alone cannot satisfy it.

## Endpoint verification

`php artisan route:list --path=training` (real output) confirms:

- `GET /v1/training/programs` — `training.program.read`
- `POST /v1/training/programs` — `training.program.manage`
- `GET /v1/training/sessions` — `training.session.manage`
- `GET /v1/training/enrollments` — `training.enrollment.manage`
- `POST /v1/training/enrollments` — `training.enrollment.manage`
- `POST /v1/training/enrollments/{id}/complete` — `training.completion.manage`
  (confirmed: the brief's stale variant is not used)
- `GET /v1/training/courses`, `GET /v1/training/records`, `GET /v1/training/reports`

Missing: program update/publish/close and enrollment cancel (see above).

## What was built

### Backend

- `programs()` now derives per-program `category`, `delivery_mode`,
  `capacity`, `enrolled_count`, `completed_count`, `remaining_seats`,
  `session_count` and `training_cost_cents` from live session/course/enrollment
  rows. Cost is integer minor units aggregated once per distinct course; no
  float, no stored denormalisation.
- `completeEnrollment()` accepts `completed_on` (`Y-m-d`) and only completes an
  `ENROLLED`/`ATTENDED` enrollment. A cancelled row returns
  `409 TRAINING_ENROLLMENT_NOT_ACTIVE`; a missing row is still `404`.
  Completion date is stored through `BusinessDate::parse()` (UTC midnight).
- Idempotent command replay, audit and projection envelopes are unchanged.

### Frontend

- `api.ts`: strict normalisers for programs/sessions/enrollments/employees,
  real command helpers (`createProgram`, `enroll`, `completeEnrollment`),
  idempotency keys, Chinese enum labels and stable reason → Chinese mapping
  (`TRAINING_CAPACITY_REACHED`, `TRAINING_ENROLLMENT_NOT_ACTIVE`, …).
- `TrainingWorkspacePage.vue`: program list with title, category, delivery
  mode (线上/线下/混合), enrolled/capacity, remaining-seat copy, status,
  integer-cent cost; create drawer; edit/publish/close confirmations reporting
  the missing routes; five states; capability guards.
- `TrainingProgramDetailPage.vue`: live program summary, session table
  (date/time/location/capacity/status), enrollment table with
  employee links to `/hr/employees/:id`, enroll dialog with inline capacity
  copy, completion dialog (result, date, score, attendance), cancel
  confirmation reporting the missing route.
- `TrainingProgramDrawer.vue`: validated create/edit form with field errors.
- `TrainingEnrollmentTable.vue`: statuses 已报名/已签到/已完成/已取消,
  capability-aware complete/cancel buttons with Chinese disabled reasons.
- Five states everywhere: loading, empty, error (request id + retry),
  forbidden (no request), partial (independent enrollment/employee reads).
- No fake rows: empty projections render explicit empty states.

## Verification (real output)

| Command | Result |
|---|---|
| `phpunit --filter='TrainingWorkspaceTest'` | **OK (4 tests, 45 assertions)** |
| `phpunit` full | **OK (1243 tests, 58,222 assertions)** |
| `vendor/bin/pint --test` (owned files) | **PASS (3 files)** |
| `vendor/bin/phpstan analyse` (owned files) | **[OK] No errors** |
| `php artisan capability:audit` | **Capability audit OK** |
| `php artisan arch:test` | **Module boundaries OK** (4 non-blocking platform warnings) |
| `vitest run tests/unit/TrainingWorkspace.spec.ts` | **12 passed / 12** |
| `pnpm test:unit` (full) | **65 files / 525 tests passed** |
| `pnpm lint` | **PASS** (`--max-warnings=0`) |
| `pnpm typecheck` | **No training errors** (see note) |
| `playwright ... --list training-workspace.spec.ts` | **3 tests listed** |

Repo-wide `pint --test` and `phpstan` also report pre-existing/concurrent
failures in `RecruitmentController.php`, `PerformanceWorkspaceTest.php` and
`tests/Feature/Talent/PerformanceWorkspaceTest.php` — other agents' files, not
edited here. Repo-wide `tsc` transiently reported
`@/modules/platform/pages/WorkflowCaseDetailPage.vue` (another agent's
in-flight file); it contains no training diagnostics.

## Missing routes / contract changes to report

- `PATCH /v1/training/programs/{id}` — program edit
- `POST /v1/training/programs/{id}/publish` — publish
- `POST /v1/training/programs/{id}/close` — close
- `POST /v1/training/enrollments/{id}/cancel` — cancel enrollment
- OpenAPI: `TrainingProgram` must add the new projection fields
  (`category`, `delivery_mode`, `capacity`, `enrolled_count`,
  `completed_count`, `remaining_seats`, `session_count`,
  `training_cost_cents`); completion request must add `completed_on`; the
  enrollment status enum must include `CANCELLED`. `docs/openapi/**` was not
  edited per the ownership restriction.
- Router: `/training` still resolves `training/index` to the legacy wrapper and
  there is no `/training/programs/:id` detail route. Lead-owned changes needed
  in `frontend/src/router/registry.ts`, `frontend/src/router/pageComponents.ts`
  and `frontend/src/modules/talent/pages/index.ts` (`training/workspace`,
  `training/programs/by-id`).
