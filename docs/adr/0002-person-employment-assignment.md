# ADR-0002: Person–Employment–Assignment with a legal-entity employer boundary

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-01 (Person–Employment–Assignment), D-02 (`tenant_id` isolation and `legal_entity_id` employer boundary)
- **Supersedes:** the working "platform baseline" ADR that previously occupied number 0002; its content now lives in ADR-0011
- **Review trigger:** a real requirement appears for a worker who is employed by more than one legal entity at once and cannot be modelled as two employments; or a module needs to store organization membership on the person rather than on an assignment; or a legal-entity boundary must be crossed inside a single payroll run

## Context and trigger

HR data has three independent truths that change on different cadences:

- the **person** (a human being, who may be rehired or transferred),
- the **employment** (the relationship with one legal employer, which determines contract, payroll, tax and social insurance),
- the **assignment** (where that employment sits in the organization, which position and which manager, for a bounded period).

Collapsing these into one "employee" row is the default industry shortcut. It makes the common case cheap and every other case wrong: a transfer rewrites history, a rehire duplicates identity, a secondment has no home, and a payroll correction cannot tell which employer owed the money. Because the approved solution fixes this as decision D-01/D-02, the schema and the module ownership must be decided before any business module writes data — hence this ADR is a W3 gate.

## Decision

1. **Three fact tables, one writer.** `persons`, `employments`, `assignments` are owned by the People module (`app/Modules/People`, migration `database/migrations/2026_11_23_000011_create_people_tables.php`). No other module writes them; everything else reads through `App\Modules\People\Contracts`.
2. **`tenant_id` isolates; `legal_entity_id` employs.** Every row carries `tenant_id`. The employment carries the employer as `legal_entity_id`; the organization unit also belongs to exactly one legal entity (`org_units.legal_entity_id`), and a position belongs to one legal entity (`positions.legal_entity_id`). `legal_entities` carries the IANA timezone and currency that later determine business dates and money.
3. **Employee number is unique per tenant and legal entity**, enforced by `employments_employee_no_unique`. The same human can therefore hold `E-100` at two employers without collision.
4. **Cross-entity movement ends and creates.** A move between legal entities terminates the old employment and creates a new one for the same person rather than editing `legal_entity_id` in place. The transfer path is `TransferFutureEffectTest` / `CrossLegalEntityTest`; an assignment whose org unit belongs to a different legal entity than its employment is rejected with a reason code.
5. **Assignment is the only place organization membership lives.** `assignments` holds `org_unit_id`, `position_id`, `manager_assignment_id`, `is_primary` and a half-open validity range. Concurrent assignments (secondment, acting) are additional rows; the current view is a projection over the as-of predicate.
6. **Reads are as-of, never "current".** All effective-dated reads use `App\Shared\Time\AsOfQuery::whereValidOn()` with half-open `[valid_from, valid_to)` and `9999-12-31` as the open end. List, detail, org tree and directory reads must agree at the same `as_of`.
7. **The employee view is a projection.** `PeopleDirectoryService` / `AsOfEmployeeQuery` compose the three tables into the employee list/detail shape. There is no `employees` table to drift.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| A single `employees` table with denormalized org/manager columns | Cheapest for the happy path, but a transfer overwrites history, rehires duplicate a person, secondments have no home, and payroll cannot attribute cost to the correct employer. |
| Person + employment, with org membership as a column on employment | Cannot express concurrent assignments (secondment/acting) and loses the assignment-level manager chain that approval routing needs. |
| `legal_entity_id` as a soft attribute that can be updated in place | Rewrites the payroll and tax subject of already-finalized periods; breaks as-of queries and correction chains. Ending and re-creating is the only reconcilable model. |
| Scope everything by `tenant_id` only | One tenant can contain several employing entities with different tax/social-insurance obligations; reports and payroll would mix them. |
| Store the "current" flag on rows instead of validity dates | Two writers race on the flag, and historical or future-dated queries become impossible. |

## Consequences

Positive:

- historical, current and future-dated views are all derived from the same rows;
- transfers and rehires are append-only and auditable;
- payroll, time and reporting can attribute facts to the correct legal employer;
- secondment and concurrent assignment are modelled rather than special-cased.

Negative / costs:

- every business query must carry an as-of predicate; a missing predicate silently returns duplicates;
- the UI needs a projection layer rather than reading one table;
- cross-entity moves require orchestration (Lifecycle) rather than a single update;
- more joins in list queries, mitigated by the `employments_asof_idx` and `assignments_*_asof_idx` indexes.

## Validation

- `tests/Feature/People/CrossLegalEntityTest.php` — assignment across legal entities rejected; cross-entity transfer ends the old employment and creates a new one; employee number unique per legal entity.
- `tests/Integration/AsOfConsistencyTest.php` — employee list, detail, org tree and directory agree at the same `as_of`, including after an org move boundary.
- `tests/Feature/People/AssignmentTimelineTest.php` — assignment history preserved across changes.
- `tests/Feature/Lifecycle/TransferFutureEffectTest.php` — a future transfer leaves today's view untouched.
- `php artisan arch:test` — no module outside People imports People models.
- `php artisan test`, `vendor/bin/phpstan analyse`.

Evidence: `evidence/logs/backend-test-current.txt`, `evidence/logs/arch-test.txt`.

## Security and privacy

Person identifiers are stored as ciphertext plus a keyed hash (`id_number_ciphertext`, `id_number_hash`) and read through field profiles P0–P3, so the employer boundary does not by itself grant access to identity fields. Authorization anchors (`SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `LEGAL_ENTITY`) are applied with `Authorizer::constrain()` before pagination, so a narrowed principal cannot page into another legal entity. See ADR-0004.

## Review triggers and rollback

Revisit if a single worker must be employed by two legal entities simultaneously under one contract, if a regulator requires organization membership to be a person attribute, or if `arch:test` cannot keep cross-module model access out. Rollback is not a schema edit: it would require a new migration that materializes an employee projection and an explicit, audited decision to abandon as-of history — which is why this ADR is treated as irreversible.
