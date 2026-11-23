# 03 — Organization, People and Employment Data Model

Status: Task 3 reference implementation
Sources: solution §5–§6, plan §4.3, decision register D-02/D-03/D-06.

## Ownership

| Table | Sole writer | Read via |
|---|---|---|
| `legal_entities`, `org_units`, `org_unit_closure`, `positions` | Organization | `App\Modules\Organization\Contracts\OrganizationDirectory` |
| `persons`, `employments`, `assignments`, `employment_terms`, `documents`, `employment_user_links` | People | `App\Modules\People\Contracts\PeopleDirectory` |
| `audit_events`, `outbox_events`, `idempotency_keys`, `file_objects` | Platform | Platform services only |

Other modules must reference `employment_id` (and optionally `assignment_id`). They never read
People or Organization models directly.

## Text ERD

```text
legal_entities 1 ── N org_units                       (tenant-scoped legal employer)
org_units      N ── N org_unit_closure                (effective-dated ancestor paths)
org_units      1 ── N positions                       (position catalog)
persons        1 ── N employments                     (natural person is long-lived)
employments    1 ── N assignments                     (org, position, manager, validity)
employments    1 ── N employment_terms                (immutable versioned clauses)
employments    1 ── N documents                       (metadata + evidence hash)
users (IAM)    1 ── N employment_user_links           (access subject ↔ employment, no FK)
```

There is deliberately **no** `employees` table and **no** `current_department_id` or
`current_position_id` on `employments`. The current organization is a projection over
`assignments` for a requested business date.

## Effective-dated tables and columns

`org_units`, `org_unit_closure`, `positions`, `employments`, `assignments` carry:

- `valid_from DATE`, `valid_to DATE`, half-open `[valid_from, valid_to)`.
- Open end is exactly `9999-12-31`.
- `recorded_at DATETIME(6) UTC`, `superseded_at DATETIME(6) nullable`.
- `source_type`, `source_id nullable`.

`employment_terms` uses `effective_from`/`effective_to` with the same half-open rule.
`CHECK valid_from < valid_to` is enforced by SQLite triggers in tests and MySQL `CHECK`
constraints in production. Application code validates the same invariant before write.

## Key constraints and indexes

| Table | Constraint / index |
|---|---|
| `legal_entities` | UNIQUE `(tenant_id, code)` |
| `org_units` | UNIQUE `(tenant_id, code)`; as-of `(tenant_id, legal_entity_id, valid_from, valid_to)` |
| `org_unit_closure` | PK `(ancestor_id, descendant_id, valid_from)`; descendant and ancestor as-of indexes |
| `positions` | UNIQUE `(tenant_id, legal_entity_id, code)` |
| `persons` | UNIQUE `(tenant_id, id_type, id_number_hash)` when identity present |
| `employments` | UNIQUE `(tenant_id, legal_entity_id, employee_no)`; as-of index |
| `assignments` | employment/org/manager as-of indexes; PRIMARY overlap enforced transactionally |
| `employment_terms` | UNIQUE `(employment_id, version)`; history is append-only |
| `documents` | UNIQUE `(owner_type, owner_id, category, version)` |
| `employment_user_links` | UNIQUE `(tenant_id, user_id, employment_id, valid_from)`; as-of index |

## Invariants

1. **One PRIMARY assignment per employment per business day.** The write service locks the
   employment and its assignment rows, then `AssignmentTimeline::assertNoPrimaryOverlap`
   rejects intersecting half-open ranges with `409 CONFLICT` / reason
   `PRIMARY_ASSIGNMENT_OVERLAP`. Concurrent SECONDMENT/MATRIX/PROJECT assignments are allowed.
2. **Legal-entity boundary.** An assignment's org must belong to the employment legal entity
   during the assignment window. Violations return `422 VALIDATION_FAILED` with reason
   `CROSS_LEGAL_ENTITY_ASSIGNMENT`.
3. **Cross-entity transfer** ends the old employment at the effective date and creates a new
   employment for the same person in the new legal entity. Full workflow is Task 5.
4. **Employee number** is unique per `(tenant_id, legal_entity_id)`; the same value is legal in
   another entity.
5. **Person dedupe** uses HMAC-SHA256 over normalized id type/value; reuse with a different ID
   type is legal. Plaintext identity and mobile are encrypted with Laravel Crypt and never
   serialized in API, audit, or outbox payloads.
6. **Terms are versioned.** Appending a version ends the previous open row at the new
   `effective_from`; historical rows are never edited. Version increments monotonically.
7. **Org moves version closure.** Existing ancestor/descendant paths are ended at
   `effective_from`; new paths are appended. `org_units.id` is the stable identity; the
   historical parent is derived from closure depth 1 rows, so as-of tree queries are exact.
   A later move must start strictly after the latest closure version.
8. **Tenant isolation.** Every directory and HTTP read is scoped by `tenant_id`; cross-tenant
   detail returns 404 to prevent enumeration.

## As-of query rules

```sql
valid_from <= :as_of
AND :as_of < valid_to
AND recorded_at <= :known_at
AND (superseded_at IS NULL OR :known_at < superseded_at)
```

- `App\Shared\Time\BusinessDate` validates real calendar dates (`2026-02-30` is rejected) and
  computes today from the legal entity IANA timezone through the injected `Clock`.
- `App\Shared\Time\DateRange` models the half-open interval and `App\Shared\Time\AsOfQuery`
  applies the validity and known-at predicates. Business code is forbidden from `whereDate`
  (architecture test).
- `as_of` defaults to today in the tenant timezone. List, detail, org tree, timeline and
  directory snapshots all use the same business date.
- A future-dated assignment does not change today's projection; it automatically becomes
  current when the business date reaches `valid_from`.
- Terminated employees are absent from the list/detail after `valid_to`; future hires are
  absent before `valid_from`.

## Privacy and documents

- P0: names, employee number, org, position, dates — visible.
- P1: mobile/email — mobile masked in employee projections.
- P2: identity number, birth date, nationality — identity never serialized; restricted fields
  are omitted or masked by the shared `FieldSet` seam.
- P3: payroll/health — out of Task 3 scope.
- Document download returns only a descriptor requiring a signed URL, never a storage key or
  raw hash, and requires both `people.contract.read` and `people.document.download`.

## Known limitations

- `positions` and `org_unit_closure` are modeled and queried, but this task exposes only the
  tree/move/impact APIs; a dedicated position CRUD API is deferred.
- `documents` stores metadata and a `file_object_id`; signed download URL issuance is modeled
  as a descriptor and belongs to the Platform file-storage task.
- Payroll cost-center impact is always `0` until Payroll (Task 7) exists.
- HTTP capability enforcement requires IAM Task 2 middleware. Until it is merged, every route
  carries `capability:<code>` metadata and the shared Authorizer is fail-closed.

## Migration and seeding

- Forward-only anonymous migration classes.
- SQLite: compatibility triggers enforce date-range ordering; MySQL: `CHECK` constraints.
- `php artisan people:seed-demo` is idempotent and seeds `DEMO` / `DEMO-CN` with synthetic
  persons, a manager/report chain, a concurrent secondment, and a future primary transfer.
- `php artisan org:rebuild-closure --legal-entity=N` appends missing closure paths.
- `php artisan people:as-of-check --date=YYYY-MM-DD` exits non-zero on overlapping PRIMARY
  assignments or orphan assignments.

## Integration seams

- `PeopleServiceProvider` binds `PeopleDirectory`, `OrganizationDirectory`, `Clock`, and a
  fail-closed `Authorizer`.
- CAPABILITY / `capability:<code>` metadata is present on every new `/v1` route.
- IAM Task 2 replaces the fail-closed Authorizer/Principal/middleware bindings; directory
  signatures in this module follow the frozen interface exactly.
