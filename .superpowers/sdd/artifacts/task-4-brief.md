# Task 4 — Case, Decision, Effect, approval runtime, outbox integration

## Context

Platform (Task 1), IAM (Task 2), People/Organization (Task 3) exist. Read `.superpowers/sdd/artifacts/interface-freeze.md`.

Authoritative sources: solution §8, §10.4; plan §4.4; D-05, D-12.

The hard rule: approval is not effect. `APPROVED != EFFECTIVE`. A Decision is immutable. Local deterministic effects apply in the same transaction as the decision; external/async effects go through Outbox. A failed effect is visible as "approved, not effective" and retryable. Every command is idempotent.

## Data model

All BIGINT PK, tenant_id, UTC.

- `workflow_templates`: id, tenant_id, code, name, version, status, definition_json (limited node types only), created_at; unique (tenant_id, code, version). Definition supports node types: START, APPROVAL, CC, CONDITION (limited declarative predicate over payload fields with operators eq/ne/gte/lte/in), END. No arbitrary script, no BPMN.
- `workflow_template_nodes`: id, template_id, key, name, type, sequence, assignee_rule_json, required_count, sla_hours nullable. Assignee rule types: USER, MANAGER_OF_SUBJECT, ORG_HEAD, ROLE, CASE_MEMBER. No arbitrary SQL.
- `cases`: id, tenant_id, case_no, type, subject_type, subject_id nullable, title, payload_json, status (`DRAFT|SUBMITTED|IN_REVIEW|APPROVED|REJECTED|WITHDRAWN|CANCELLED`), effect_status (`NONE|PENDING|SCHEDULED|APPLIED|PARTIAL|FAILED|REVERSED`), requester_user_id, template_id, template_version, template_snapshot_json, business_date, submitted_at, closed_at, created_at, updated_at.
- `workflow_instances`: id, case_id unique, current_node_key, status (`RUNNING|COMPLETED|CANCELLED`), started_at, completed_at.
- `workflow_tasks`: id, case_id, node_key, assignee_user_id, status (`PENDING|APPROVED|REJECTED|DELEGATED|CANCELLED|EXPIRED`), due_at, acted_at, created_at. Unique (case_id, node_key, assignee_user_id).
- `decisions`: id, tenant_id, case_id, node_key, actor_user_id, decision_seq, action (`APPROVE|REJECT|RETURN|WITHDRAW|DELEGATE|CC`), comment, actor_snapshot_json (roles/capabilities/auth_version), template_version, evidence_hash nullable, decided_at, request_id. Unique (case_id, node_key, actor_user_id, decision_seq). Append-only; model blocks update/delete.
- `case_events`: id, case_id, event_type, actor_user_id nullable, payload_json, occurred_at. Append-only timeline.
- `effects`: id, tenant_id, case_id, decision_id nullable, effect_type, aggregate_type, aggregate_id, effect_seq, status (`SCHEDULED|APPLIED|FAILED|REVERSED`), scheduled_for date nullable, applied_at nullable, failed_at nullable, failure_reason nullable, attempts, reversal_of_id nullable, payload_json, created_at, updated_at. Unique (effect_type, aggregate_id, effect_seq).
- `case_members`: case_id, user_id, role (`REQUESTER|APPROVER|OBSERVER|HR_ADMIN`).

No business tables get an `approval_status` column. They may have domain lifecycle status derived from effects.

## Effect framework

- `EffectHandler` interface: `supports(string $effectType): bool`, `apply(Effect $effect): EffectResult`, `reverse(Effect $effect): EffectResult` (reverse may throw if unsupported).
- `EffectRegistry` maps effect types to handlers. Reject unknown types at decision time (422) rather than at execution.
- `EffectExecutor`:
  - Local effect: runs inside DB transaction with the Decision. If it throws, the whole transaction rolls back EXCEPT the Decision must be recorded — implement by committing the Decision in an outer transaction with a nested savepoint for the effect, then recording Effect FAILED in a compensating transaction; document the exact approach and test it. The observable contract: Decision survives, Effect is FAILED, case shows APPROVED/FAILED, retry can succeed.
  - Idempotency: before applying, lock effect row `FOR UPDATE`; if already APPLIED return same result; increment attempts only on actual try.
  - Retry endpoint: only FAILED effects; re-executes; max 5 attempts, then remains FAILED with escalation flag.
  - Scheduled effect: command `php artisan effects:run-due --limit=` processes `scheduled_for <= today`, idempotently.
- `DecisionService::decide(caseId, actor, action, comment, idempotencyKey)`:
  - Actor must be current node candidate (or admin override with capability + reason, audited).
  - `APPROVE` with required_count satisfied completes node; final node completes instance, sets Case APPROVED, dispatches effects.
  - `REJECT`/`RETURN` transitions correctly; `WITHDRAW` only requester and only before final decision.
  - Unique constraint + row lock prevent duplicate concurrent approvals.
- Template snapshot: on submit, copy template definition into `cases.template_snapshot_json`; later template edits never affect live/historical cases.
- Reassignment: when assignee is terminated/transferred/on long leave (provided via injectable `AssigneeEligibility` contract), `php artisan workflow:reassign` re-resolves: new manager -> skip-level -> HR admin; writes a case event and Decision-like reassignment record (use case_events; do not mutate Decision).
- Delegation: proxy has valid_from/valid_to, max_amount nullable, scope_json; enforced; audited.

## Endpoints

- `POST /v1/cases` (capability `workflow.case.create`) — idempotent; creates and optionally submits.
- `POST /v1/cases/{id}/submit`
- `POST /v1/cases/{id}/decisions` (capability `workflow.case.decide`) — action, comment, Idempotency-Key.
- `POST /v1/cases/{id}/withdraw`
- `GET /v1/cases` — my requests / my approvals / all (capability-scoped), filters by status/effect_status/type/date.
- `GET /v1/cases/{id}` — detail with both statuses.
- `GET /v1/cases/{id}/timeline` — chronological: submitted, each decision, each effect, notifications, corrections; includes separate `application` and `effect` state.
- `POST /v1/effects/{id}/retry` (capability `workflow.case.admin`) — 202 accepted or 200 applied.
- `GET /v1/tasks/my` — pending approvals for current user, with case summary and SLA.
- `GET /v1/workflow/templates` (read), `POST /v1/workflow/templates` (admin, validated node types only).
- `POST /v1/delegations`, `DELETE /v1/delegations/{id}`.

All guarded, tenant-scoped, audited. List queries must not N+1.

## Commands

- `php artisan effects:run-due`
- `php artisan workflow:reassign`
- `php artisan workflow:seed-templates` — idempotent templates: leave request, transfer, contract renewal, onboarding, offboarding, payroll approval, generic approval.
- `php artisan workflow:check` — verify no case has APPROVED but effect PENDING beyond SLA; exit non-zero only for invariant violations (missing effect rows for completed cases).

## Tests (must pass)

Feature:
- `DecisionImmutabilityTest`: update/delete throws; historical snapshot unaffected by template edit; actor snapshot stored.
- `ConcurrentApprovalTest`: two parallel approve calls => exactly one Decision, one Effect; idempotent replay returns same result.
- `EffectIdempotencyTest`: same effect applied twice only once; unique constraint; retry after success is no-op; retry after failure works; max attempts enforced.
- `ApprovedNotEffectiveTest`: handler throws => Decision committed, Effect FAILED, case effect_status FAILED, timeline shows both; retry applies; local failure leaves no partial business writes.
- `ScheduledEffectTest`: future effect not applied today; fake clock to due date applies once; missed schedule runs next time exactly once.
- `ReassignTest`: manager changes => pending task reassigned with case event; terminated assignee skipped; no duplicate task.
- `DelegationTest`: proxy within range works; out of range/expired/over amount rejected; audited.
- `TemplateVersionTest`: edit template v2; v1 cases keep v1 nodes; new cases use v2.
- `StatusSeparationTest`: API never reports a single combined "completed"; application and effect status independent.
- `OutboxOnDecisionTest`: external effect emits outbox event with only IDs/versions, no PII.
- `AuthorizationTest`: requester sees own; approver sees assigned; unrelated user 404; admin retry requires reason and is audited.
Unit:
- WorkflowStateMachineTest (all transitions incl. invalid), ConditionEvaluatorTest (only allowed operators; rejects arbitrary expressions), EffectRegistryTest, IdempotencyHashTest.
Contract:
- Case create/decision/timeline schemas in OpenAPI.

## Constraints

- No approval status columns on domain tables.
- No arbitrary expressions/scripts in workflow definitions; only declarative conditions over whitelisted payload keys.
- Decision/audit append-only enforced at model level and documented at DB privilege level.
- No PII in outbox payloads.
- Effects scoped by tenant and authorized.
- Update OpenAPI and `docs/06-workflows.md` (state machines, effect types, failure semantics, timeline contract).
- Run full backend suite, pint, phpstan, capability:audit, arch:test.

## Report

Write `.superpowers/sdd/artifacts/task-4-report.md` with files, exact command results, decisions, gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
