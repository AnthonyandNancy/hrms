# 01 — HRMS V1.0 Architecture

Status: Accepted baseline for the reference implementation.
Authoritative sources: approved solution v1.1 §04, §10 and plan v1.1 §02.

This document describes how the repository implements the approved architecture. It does not reopen decisions D-01–D-25; changes require an ADR.

## 1. Five-layer model

The solution describes five layers. A feature change should first identify which layer it changes.

| Layer | Responsibility | Typical objects | Must not do |
| --- | --- | --- | --- |
| Contract | REST/OpenAPI, commands, queries, errors, idempotency | `/v1/...`, DTOs, error codes, capability metadata | Expose internal table structure |
| Fact kernel | People, employment, organization, time, terms, leave, payroll truth | Person, Employment, Assignment, OrgUnit, Ledger, PayrollRun | Let peripheral modules own employee truth; overwrite history |
| Decision / effect | Intent, approval, application, compensation | Case, Decision, Effect, Process Manager | Write approval state into business fact tables |
| Ledger | Append-only balances and immutable financial/leave entries | LeaveLedgerEntry, PayrollRun, Payslip, AuditEvent, Outbox | Mutate posted entries; store derived current values as truth |
| Projection | Rebuildable read models and reports | Current profile, headcount, leave balance view, dashboards | Be used as a source of truth or written back to kernel tables |

The contract layer is the only public API surface. The fact kernel and ledger are append/version oriented. Projections may lag and must expose freshness; they are never used to reconstruct legal or payroll facts.

## 2. Modules and ownership

Eleven modules live under `backend/app/Modules/<Module>/`. Directories may exist before their implementation; an empty directory is a placeholder, not a feature claim.

| Module | Owned tables (reference names) | Allowed dependencies |
| --- | --- | --- |
| IAM | `users`, `sessions`, `capabilities`, `authority_bindings`, `auth_events`, `roles`, `role_capabilities`, `user_roles`, `iam_data_scope_policies`, `iam_field_policies` | Platform Contracts, Shared |
| Organization | `org_units`, `org_unit_closure`, `positions` | People Contracts/IDs, Shared |
| People | `persons`, `employments`, `assignments`, `employment_terms`, `documents`, `employment_user_links`, `employment_contracts`, `contract_templates` | Organization Contracts/IDs, IAM Contracts/IDs, Lifecycle `EmploymentContractVersionLedger` contract, Shared |
| Lifecycle | `cases`, `decisions`, `effects`, `lifecycle_tasks`, `lifecycle_intents`, `onboarding_checklist_templates`, `employment_contract_versions` | People, Workflow Contracts, Time/IAM/Talent Contracts, Shared |
| Workflow | `workflow_definitions`, `workflow_versions`, `workflow_instances`, `workflow_tasks`, `workflow_delegations` | IAM Contracts, Shared |
| Time | `shifts`, `schedules`, `punch_records`, `attendance_days`, `attendance_corrections`, `leave_accounts`, `leave_ledger_entries`, `leave_types`, `leave_balances`, `leave_requests`, `overtime_records`, `overtime_settlements`, `overtime_requests` | People, Workflow Contracts, Shared |
| Payroll | `payroll_items`, `payroll_schemes`, `payroll_runs`, `payslips`, `payslip_lines`, `payroll_input_snapshots`, `employee_compensations`, `salary_adjustments` | Time, People, Workflow Contracts, Shared |
| Talent | `recruitment_requisitions`, `candidates`, `interviews`, `offers`, `performance_cycles`, `performance_templates`, `performance_reviews`, `performance_appeals`, `performance_indicators`, `performance_goals`, `performance_feedback`, `performance_calibrations`, `training_programs`, `training_sessions`, `training_enrollments` | Lifecycle/People/Workflow Contracts, Shared |
| Engagement | `notices`, `notifications` | Read-only Contracts from all modules, Shared |
| Reporting | `report_definitions`, `export_jobs`, `projections` | Read-only projections/Contracts, Shared |
| Platform | `audit_events`, `outbox_events`, `idempotency_keys`, `file_objects`, `sys_dictionaries`, `sys_parameters`, `menu_overrides`, `notification_templates`, `notification_outbox`, `notification_deliveries` | Shared only; **no business module** |

The machine-readable ownership list lives in `App\Shared\Architecture\ModuleBoundaryScanner::TABLE_OWNERS`. Add a table there in the same migration commit that introduces it; an unowned table referenced through `DB::table()` is reported as a warning.

Rules:

- Every table has exactly one writing module. Others read through `Contracts` or projections.
- `App\Modules\<Module>\Models` and internal `Services`/`Http` classes are private. Only `Contracts` is public across modules.
- Platform may not depend on any business module. Business modules register capabilities, jobs and events through Platform contracts.
- Cross-module consistency uses Case/Decision/Effect or Outbox events; never two services writing and committing separately.
- Reporting reads projections, not live module tables.

## 3. Dependency rule and enforcement

Default rule: a module may import only:

- `App\Shared\*` (cross-cutting primitives, no business rules);
- its own namespace (`App\Modules\<SameModule>\*`);
- other modules' `Contracts\*` interfaces, DTOs and IDs.

Cross-module `Models`, `Services`, `Http`, `Policies`, `Providers`, `Exceptions` and other internals are violations.

`App\Shared\Architecture\ModuleBoundaryScanner` tokenizes module PHP source and reports:

- `use` imports and fully-qualified references to another module's non-public namespace;
- string literals containing `App\Modules\...` references;
- `DB::table(...)` / `DB::connection(...)->table(...)` access to a table owned by another module (violation) and unknown tables (warning);
- `Schema::*` use anywhere in application modules (violation; migrations are outside `app/Modules`).

Run `php artisan arch:test`. It exits non-zero on violations and prints file/line provenance. CI runs the same command.

### Explicit allowlist

`ModuleBoundaryScanner::EXPLICIT_ALLOWLIST` is the single place for deliberate, narrow exceptions. Each entry must be `FQCN => reason string` and the reason must also be documented here. The intended default is empty.

| FQCN | Reason | Current status |
| --- | --- | --- |
| _(none)_ | — | Business modules do not need Platform Models yet. Platform Models `AuditEvent`/`OutboxEvent` are used only by Platform implementation and tests. |

If a business module must later reference `App\Modules\Platform\Models\AuditEvent` or `OutboxEvent`, prefer a Platform `Contracts` writer instead. If that is genuinely impossible, add exactly that FQCN to the constant with a dated reason and update this table in the same commit. Platform `Services` and `Http` are never allowlisted.

## 4. Request, audit, outbox and idempotency conventions

### Request id

- `App\Shared\RequestId` middleware accepts a safe inbound `X-Request-Id` or generates a ULID.
- The id is available through `App\Shared\RequestContext`, is attached to every JSON envelope as `request_id`, returned in the `X-Request-Id` header, and added to structured log context.
- Error responses use the same middleware and therefore carry the same id.

### API envelope

Success and failure share:

```json
{"code":"OK","message":"OK","data":{},"request_id":"01J..."}
```

Errors use `App\Shared\ErrorCode` (`UNAUTHENTICATED`, `FORBIDDEN`, `NOT_FOUND`, `CONFLICT`, `VALIDATION_FAILED`, `RATE_LIMITED`, `INTERNAL`, `SERVICE_UNAVAILABLE`, ...). `App\Shared\Exceptions\ExceptionRenderer` is the only place that maps exceptions to public envelopes; internal messages, SQL, paths and stack traces are never exposed.

### Audit

- Owned by Platform (`audit_events`), append-only at model level (update/delete throw).
- Audit records carry actor, action, aggregate, before/after JSON, IP/user agent and `request_id`.
- Modules write audit entries through a Platform contract when their HTTP surface lands; they do not update audit rows.

### Outbox

- Owned by Platform (`outbox_events`).
- `App\Modules\Platform\Services\OutboxService::record()` writes inside the caller's transaction. It does not commit or open a transaction.
- Events carry ID, type, aggregate type/id/version, occurrence time and a narrow payload of IDs and versions only.
- Sensitive payload keys (identity numbers, credentials, bank/health/contact data) are rejected recursively before insert.
- The transport-neutral `OutboxDispatcher` contract is bound to the log dispatcher in this baseline; a broker adapter can replace it without module changes.

### Idempotency

- Owned by Platform (`idempotency_keys`).
- Commands accept `Idempotency-Key`; `IdempotencyService` reserves key+scope+payload hash, replays completed status/body, rejects payload reuse with 409, and releases failed reservations.
- Reservations and business writes must share one transaction.

## 5. Capability metadata

Every `/v1` route must be either:

- in the exact public allowlist `v1/health`, `v1/meta`; or
- guarded by `capability:<code>` middleware, or by route default `capability`.

`php artisan capability:audit` fails and lists unguarded routes. It also warns when a guarded route has no entry in `backend/tests/Authorization/endpoint_matrix.php`; the manifest is extended with the four mandatory negative cases as modules land. Capability codes are code-owned, not database-created.

## 6. Intentionally absent from V1.0

The following are deliberate scope exclusions (solution §13.4), not unfinished accidents:

- BPMN engine or a generic visual process designer; Workflow uses typed cases and local deterministic effects.
- Microservices, service mesh, Kubernetes and distributed transactions; V1.0 is a modular monolith with one database and one transactional boundary.
- `condition_json`, arbitrary field policies, independent ownership tables or user-authored `CUSTOM` SQL in authorization.
- Event sourcing as the primary store; audit/outbox are evidence, not the fact kernel.
- Graph databases, native mobile apps, face-recognition algorithms, a tax filing engine or bank clearing.
- Multi-tenant SaaS control plane; `tenant_id` is an isolation column, not a commercial tenancy product.
