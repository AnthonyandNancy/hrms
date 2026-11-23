# Task 5 — Lifecycle verticals and Talent thin slices

## Context

Platform, IAM, People/Org, and Workflow exist. Read `.superpowers/sdd/artifacts/interface-freeze.md`.

Authoritative sources: plan §4.5, §4.8; solution §8, §9.5; UAT-01, 02, 03, 07, 08.

This task implements business flows as Case + Decision + Effect, never by directly mutating domain state from controllers. It also delivers recruitment, performance, and training thin slices that reuse the same kernel.

## Lifecycle services/effect types

Each flow has a Case type, template, payload schema, and registered EffectHandler. Effects must be idempotent and use `EffectResult`.

1. **Onboarding** (`EMPLOYMENT_ONBOARD`)
   - Input: person (existing person_id or new person), legal_entity_id, employee_no, org_unit_id, position_id, manager_employment_id, hire_date, employment_type, terms, optional candidate_id.
   - Validation: dedupe person, employee_no uniqueness, org belongs to entity, manager active, no overlapping active employment for same person+entity.
   - Effect creates Employment + PRIMARY Assignment + Terms version 1 + onboarding checklist tasks; emits `employment.created.v1`.
   - Checklist: account provisioning request, contract signature, equipment, payroll enrollment, probation end date; each task independent and auditable.
2. **Probation confirmation** (`PROBATION_CONFIRM`)
   - Effect transitions Employment PROBATION -> ACTIVE with effective date; late/early rules configurable with max bounds; never mutates historical terms.
3. **Transfer / job change** (`ASSIGNMENT_CHANGE`)
   - Input: new org/position/manager, effective_date, reason, optional new salary_scheme_code.
   - Effect: end current PRIMARY assignment at effective_date (valid_to = effective date), append new PRIMARY; optional new terms version. Future effect must not alter current view before effective date.
   - Cross-legal-entity transfer: end old Employment; create new Employment for same Person; preserve seniority rules; explicit secondment alternative for temporary cross-entity work.
4. **Salary change** (`TERMS_CHANGE`)
   - Effect appends EmploymentTerms version effective on date; never rewrites history; payroll uses terms as-of period.
5. **Offboarding** (`EMPLOYMENT_OFFBOARD`)
   - Input: last_working_date, termination_type, reason_code, checklist.
   - Validation: checklist items must be resolved or explicitly waived with reason; unpaid leave balance policy; final payroll flag.
   - Effect: sets Employment status TERMINATED and valid_to = last_working_date + 1 day (half-open), ends assignments at last_working_date, revokes user sessions/increments auth_version via an IAM contract, disables delegation, cancels pending export tasks, emits events.
6. **Contract renewal/change** (`CONTRACT_RENEW`)
   - Effect adds a new EmploymentTerms version and Document version; expiry reminder is a projection, not a contract status.
7. **Rehire** (`REHIRE`)
   - Same Person, new Employment; seniority start rule explicit; no reuse of old employment id.

## Talent thin slices

- Recruitment: `requisitions` (approved headcount request via Case), `candidates` (dedupe by email/mobile hash), `interviews` (schedule + feedback), `offers` (versioned, approval via Case, accepted/declined). Offer acceptance can emit an onboarding Case draft with candidate linkage.
- Performance: `performance_cycles`, `performance_templates`, `performance_reviews` (self, manager, calibrated score nullable), `performance_appeals` (Case). Thin slice: one cycle, goals as text, ratings, submit, appeal.
- Training: `training_programs`, `training_sessions`, `training_enrollments`, attendance and completion result. Reporting by department/person/period.
- All three reuse IAM capabilities, Audit, Outbox, Idempotency, Case/Decision/Effect.

## API

Lifecycle:
- `POST /v1/lifecycle/onboarding` (creates a Case; returns case + draft employment intent)
- `POST /v1/lifecycle/probation-confirmations`
- `POST /v1/lifecycle/transfers`
- `POST /v1/lifecycle/salary-changes`
- `POST /v1/lifecycle/offboardings`
- `POST /v1/lifecycle/contract-renewals`
- `POST /v1/lifecycle/rehires`
- `GET /v1/lifecycle/cases/{caseId}` — domain projection of a case
- `GET /v1/employees/{id}/onboarding-checklist`, `PATCH /v1/onboarding-tasks/{id}`

Talent:
- `POST/GET /v1/recruitment/requisitions`, `POST/GET /v1/recruitment/candidates`, `POST /v1/recruitment/candidates/{id}/interviews`, `POST /v1/recruitment/offers`, `POST /v1/recruitment/offers/{id}/accept`
- `POST/GET /v1/performance/cycles`, `POST /v1/performance/reviews`, `POST /v1/performance/reviews/{id}/submit`, `POST /v1/performance/reviews/{id}/appeals`
- `POST/GET /v1/training/programs`, `POST /v1/training/sessions`, `POST /v1/training/enrollments`, `POST /v1/training/enrollments/{id}/complete`
All capability-guarded, idempotent commands, tenant-scoped, audited.

## Rules that must be tested

- Onboarding cannot create a duplicate Person with same id hash; candidate conversion is idempotent.
- Nothing is visible as effective before approval AND effect application; future-dated effects do not change today's as-of view.
- Offboarding revokes access in the SAME transaction as the local effect (through IAM contract), and all sessions fail on next request.
- Transfer preserves history: as-of before effective date returns old org, after returns new; old assignment remains queryable.
- Cross-entity transfer creates a new Employment, leaves old one terminated, and preserves Person identity.
- Contract renewal creates version N+1; old version still queryable as-of old date.
- Rehire creates new Employment; old employment remains terminated.
- Salary change does not modify prior payroll runs (no payroll yet: assert terms history only).
- Performance appeal is a Case; original review immutable.
- Recruitment offer acceptance is idempotent and cannot create two onboardings.
- Training enrollment capacity enforced under concurrency.

## Commands

- `php artisan lifecycle:seed-templates`
- `php artisan lifecycle:demo --scenario=onboarding|transfer|offboarding|rehire`
- `php artisan lifecycle:integrity-check` — employments with >1 active PRIMARY, terminated employments with active assignments, offers with multiple accepted versions, checklists incomplete but employment active.

## Tests (must pass)

Feature:
- `OnboardingEffectTest`, `OnboardingDedupTest`, `TransferFutureEffectTest`, `CrossEntityTransferTest`, `OffboardingChecklistTest`, `OffboardingRevokesAccessTest`, `ContractRenewalTest`, `RehireTest`, `SalaryChangeHistoryTest`.
- `RecruitmentToHireTest`, `OfferIdempotencyTest`, `PerformanceCycleTest`, `PerformanceAppealTest`, `TrainingEnrollmentTest`, `TrainingCapacityConcurrencyTest`.
- `LifecycleAuthorizationTest`: HR vs manager vs self scope; manager cannot onboard into another entity.
Integration:
- `LifecycleToPeopleTest`: effects produce valid People/Org rows satisfying kernel invariants.
- `LifecycleToIamTest`: offboarding revokes sessions.
- `UatOnboardingToFirstPayrollPreparationTest` (pre-payroll part): onboarding produces the exact input contract payroll will later consume.
Unit:
- ChecklistPolicyTest, SeniorityRuleTest, OfferStateMachineTest, PerformanceStateMachineTest.

## Constraints

- No direct domain writes from controllers; all through Case/Decision/Effect services.
- No duplicated employee truth in Talent tables; reference `employment_id`/`person_id`.
- Candidate PII minimized: no ID number at recruitment stage; email/mobile hashed for dedupe, encrypted for display when permitted.
- Thin slices explicitly fence out complex calibration, talent review, learning paths, interview scheduling integrations.
- Update OpenAPI, `docs/07-business-rules.md`.
- Run full backend suite, pint, phpstan, capability:audit, arch:test.

## Report
Write `.superpowers/sdd/artifacts/task-5-report.md` with files, exact command results, decisions, gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
