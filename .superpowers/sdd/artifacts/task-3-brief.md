# Task 3 — Organization and People fact kernel

## Context

Build on Task 1 Platform and Task 2 IAM. Task 2 owns `users` and authorization; this task owns organizational and employment truth. Read `.superpowers/sdd/artifacts/interface-freeze.md` first.

Authoritative sources: solution §5, §6; plan §4.3 and §6.1–6.2; decision register D-02, D-03, D-06.

Core requirement: Person–Employment–Assignment, legal-entity boundary, versioned org closure, half-open time intervals, as-of queries, terms/documents. "Current department" must never be written back onto Employment.

## Data model

All BIGINT unsigned PKs, `tenant_id`, DATETIME(6) UTC, DATE business dates.

- `legal_entities`: id, tenant_id, code unique per tenant, name, country_code, timezone (IANA validated), currency_code (ISO 4217), status, timestamps.
- `org_units`: id, tenant_id, legal_entity_id, parent_id nullable, code unique per tenant, name, type (COMPANY/DIVISION/DEPARTMENT/TEAM/COST_CENTER/SHARED_SERVICE), status, valid_from, valid_to (`9999-12-31` open end), recorded_at, superseded_at, source_type, source_id. CHECK valid_from < valid_to.
- `org_unit_closure`: ancestor_id, descendant_id, depth, valid_from, valid_to; PK (ancestor_id, descendant_id, valid_from). Moving an org ends old closure rows and appends new rows; never rewrite old history.
- `positions`: id, tenant_id, legal_entity_id, org_unit_id nullable, code, title, grade, headcount_limit, valid_from, valid_to.
- `persons`: id, tenant_id, legal_name, preferred_name nullable, id_type nullable, id_number_ciphertext nullable (encrypted cast), id_number_hash nullable (HMAC for dedupe), birth_date, gender nullable, nationality nullable, mobile_ciphertext, email, timestamps. Unique per tenant on id_type+id_number_hash where present.
- `employments`: id, tenant_id, person_id, legal_entity_id, employee_no, employment_type (FULL_TIME/PART_TIME/FIXED_TERM/INTERN/CONTRACTOR), status (PROBATION/ACTIVE/LEAVE/SUSPENDED/TERMINATED), valid_from, valid_to, hire_date, termination_date nullable, seniority_start_date, recorded_at, superseded_at nullable, source_type, source_id. Unique (tenant_id, legal_entity_id, employee_no). CHECK valid_from < valid_to.
- `assignments`: id, tenant_id, employment_id, org_unit_id, position_id nullable, manager_assignment_id nullable, assignment_type (PRIMARY/SECONDMENT/MATRIX/PROJECT), is_primary bool, valid_from, valid_to, recorded_at, superseded_at, source_type, source_id. Indexes per plan. CHECK valid_from < valid_to.
- `employment_terms`: id, tenant_id, employment_id, version int, effective_from date, effective_to date, probation_end_date nullable, work_location, work_schedule, salary_scheme_code nullable, notice_period_days, confidentiality bool, non_compete bool, signed_at nullable, source_type, source_id, recorded_at. Unique (employment_id, version).
- `documents`: id, tenant_id, owner_type, owner_id, category (CONTRACT/ID/EDUCATION/CERTIFICATE/OTHER), file_object_id, version, signed_at nullable, sha256, expires_at nullable, retention_class, legal_hold bool, created_at.

Do not add FKs into IAM tables. Provide the People/Organization directory implementations that IAM contracts bind to.

## Contracts to implement

`App\Modules\People\Contracts\PeopleDirectory`:
- `employmentForUser(int $tenantId, int $userId): ?int`
- `managedEmploymentIds(int $tenantId, int $employmentId, string $on): array`
- `employmentIdsForLegalEntities(int $tenantId, array $legalEntityIds, string $on): array`
- `employmentSnapshot(int $tenantId, int $employmentId, string $on): ?EmploymentSnapshot` (DTO: ids, legal entity, org unit, position, manager employment id, status, employee_no)
- `employmentExistsInTenant(int $tenantId, int $employmentId): bool`
- `legalEntityIdsForEmployment(int $tenantId, int $employmentId): array`

`App\Modules\Organization\Contracts\OrganizationDirectory`:
- `orgUnitIds(int $tenantId, array $orgIds, string $on): array`
- `subtreeIds(int $tenantId, int $orgId, string $on): array`
- `tree(int $tenantId, int $legalEntityId, string $on): array`
- `legalEntityOfOrg(int $tenantId, int $orgId, string $on): ?int`

Bind them in a service provider. IAM tests may keep fakes.

## Domain invariants

- Non-overlapping PRIMARY assignments per Employment on any business day; enforce in a transaction with row lock plus helper `AssignmentTimeline::assertNoPrimaryOverlap`.
- Assignment org must be active and belong to the Employment's legal entity for the assignment validity window; otherwise 422 `CROSS_LEGAL_ENTITY_ASSIGNMENT`.
- Cross-legal-entity transfer requires ending old Employment and creating a new Employment for the same Person; expose validation now, full transfer in Task 5.
- Employee number unique per tenant+legal entity.
- Org closure after a move: historical as-of subtree queries return old structure; current queries return new.
- All list/detail views use `as_of`; default today in tenant timezone; invalid dates 422.
- No writes to historical rows except setting `valid_to`/`superseded_at` and appending a new version.

## As-of infrastructure

`App\Shared\Time\BusinessDate`, `DateRange` half-open, `AsOfQuery` helper (`whereValidOn($date)`, `whereKnownAt($timestamp)`), `Clock` contract + `SystemClock` + `FrozenClock`. All queries use the helper; architecture test rejects `whereDate` in business code.

Endpoints:
- `GET /v1/employees?as_of=&org_unit_id=&q=&page=&page_size=` — paginated, authorization-constrained employees with current assignment projection.
- `GET /v1/employees/{employment_id}?as_of=` — profile, current assignment, manager, terms snapshot, active contracts; 404 outside scope.
- `POST /v1/persons`
- `POST /v1/employments`
- `POST /v1/employments/{id}/assignments`
- `POST /v1/employments/{id}/terms`
- `POST /v1/employments/{id}/documents`
- `GET /v1/employments/{id}/timeline?from=&to=`
- `GET /v1/org-units/tree?as_of=&legal_entity_id=`
- `POST /v1/org-units`
- `POST /v1/org-units/{id}/move`
- `GET /v1/org-units/{id}/impact?new_parent_id=` — affected employee count, manager count, payroll cost-center count (0 until Task 7), cross-entity warning.

Every endpoint capability-guarded (`people.*`, `org.*`), tenant-scoped, as-of correct, audited. Hidden identity numbers never appear in any response, log, or audit payload. P2 fields masked by default.

## Commands

- `php artisan people:seed-demo` — idempotent demo legal entity/org tree/persons/employments/assignments/terms, including one concurrent assignment, one secondment, one future transfer.
- `php artisan org:rebuild-closure --legal-entity=`
- `php artisan people:as-of-check --date=` — overlapping PRIMARY assignments or orphan assignments; exit non-zero on violations.

## Tests (must pass)

Feature:
- `AssignmentTimelineTest`: concurrent second assignment allowed; two PRIMARY overlapping rejected; future assignment does not change today; half-open boundaries; manager chain.
- `OrgClosureTest`: subtree as-of before/after move; no cycles; moved org historical query unchanged.
- `AsOfQueryTest`: T-1/T/T+1 for employment, assignment, terms; terminated employee absent after termination date; future hire absent before hire date.
- `CrossLegalEntityTest`: assignment to other entity rejected; cross-entity employment transfer contract; employee_no uniqueness per legal entity; same number allowed in another entity.
- `PersonDedupTest`: same id_type+hash rejected; different id type allowed; encryption at rest (ciphertext != plaintext).
- `TermsVersionTest`: version increments; historical terms as-of; no in-place edit.
- `FieldPrivacyTest`: id number never serialized; mobile masked for P1; document download requires capability.
- `AuthorizationScopeTest`: SELF/MANAGED/ORG_UNIT/ORG_SUBTREE/LEGAL_ENTITY through real directories; cross-tenant 404.
Unit:
- DateRangeTest, BusinessDateTest, ClosureBuilderTest, AssignmentOverlapTest.
Integration:
- `DirectoryContractTest`: IAM Authorizer resolves real directories; manager sees direct reports; HR sees legal entity; employee sees self.
- `AsOfConsistencyTest`: employee list, detail, org tree, headcount projection agree at same as_of.
- `ArchitectureBoundaryTest`: People/Organization do not write each other's tables; no business logic in controllers.

## Constraints

- Identity/mobile encrypted at rest; hash for dedupe; never log plaintext.
- No `current_department_id`/`current_position_id` on employments.
- No soft delete for effective-dated facts; use validity end.
- Validate dates as real calendar dates; no timezone-shifted DATE.
- Migrations SQLite-compatible for tests and MySQL-compatible for production.
- Update OpenAPI and `docs/03-data-model.md` (ERD text, invariants, indexes, as-of rules).
- Run full backend suite, pint, phpstan, capability:audit, arch:test.

## Report

Write `.superpowers/sdd/artifacts/task-3-report.md` with files, exact command results, decisions, gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
