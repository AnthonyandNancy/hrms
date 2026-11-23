# Parallel work notes (binding)

The following applies to all worktrees based on baseline `b869706`.

## What exists at baseline
- `Shared`: ApiResponse, ErrorCode, RequestContext, RequestId, DomainException family, ExceptionRenderer.
- `Platform`: audit_events/outbox/idempotency/file_objects models+migrations; OutboxService, IdempotencyService, HealthService; `/v1/health`, `/v1/meta`.
- Laravel 13.17, PHP 8.4, SQLite test DB, 28 passing tests.

## What does NOT exist yet
- IAM users/sessions/capabilities/bindings.
- People/Organization tables and directories.
- Workflow Case/Decision/Effect.
- `capability:audit` and `arch:test` commands (Task 1B branch adds them).
- OpenAPI file (Task 1B branch adds it).

## Conflict avoidance
- Do not modify files owned by another work package:
  - IAM owns `app/Modules/IAM/**`, `app/Shared/Authorization/**`, IAM migrations, `docs/02-permissions.md`.
  - People/Organization owns `app/Modules/People/**`, `app/Modules/Organization/**`, People/Org migrations, `docs/03-data-model.md`.
  - Workflow owns `app/Modules/Workflow/**`, Workflow migrations, `docs/06-workflows.md`.
  - Time owns `app/Modules/Time/**`, Time migrations, time/leave section of `docs/07-business-rules.md`.
  - Frontend owns `frontend/**`.
  - Ops owns `deploy/**`, `.github/**`, migration framework, runbooks.
  - Task 1B owns root files, `docs/01-architecture.md`, `docs/openapi/openapi.yaml`, `app/Shared/Architecture/**`, and console commands `arch:test` / `capability:audit`.
- If you need to edit `bootstrap/providers.php`, `bootstrap/app.php`, `routes/api.php`, `config/hrms.php`, or a shared doc, add the smallest possible additive change and note it in your report for the merge coordinator.
- Never edit another module's migration or model.

## Cross-module ports when dependency is not merged
Because work packages are parallel, define narrow **ports** in your own module's `Contracts` namespace and bind a default/test fake. Do not import another module's Models or Services. Later integration will bind real adapters.
- IAM: define `PersonEmploymentDirectory` and `OrganizationScopeDirectory` ports under `App\Shared\Authorization\Contracts`; provide fakes. People/Organization will implement them.
- Time: define `EmploymentDirectory` under `App\Modules\Time\Contracts`; provide a fake. It will later be bound to People.
- Payroll: define `PayrollInputDirectory` under `App\Modules\Payroll\Contracts`.
- Workflow: define `ActorDirectory`/`AssigneeEligibility` in `App\Modules\Workflow\Contracts`.

## Route protection
All new `/v1` routes except health/meta/login must carry middleware metadata `capability:<code>`. Even before `capability:audit` exists in your branch, the metadata is mandatory. Do not create an alternative audit command.

## Commit and report
Commit logical slices in your worktree branch. Write your report under `.superpowers/sdd/artifacts/`. Do not merge or rebase onto main; the coordinator will integrate.
