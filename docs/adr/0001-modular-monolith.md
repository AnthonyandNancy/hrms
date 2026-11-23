# ADR-0001: Modular monolith with enforced module boundaries

- **Status:** Accepted — 2026-11-23
- **Decision IDs:** D-08 (solution appendix E); related D-04, D-07
- **Supersedes:** none
- **Review trigger:** team exceeds 20 engineers across independent module teams, sustained per-module deployment bottleneck, or cross-module consistency becomes impossible inside one database transaction

## Context and trigger

The approved solution rejects module-first decomposition as the architecture. Employee truth must have one owner across organization, time, payroll and reporting, and the team baseline is eight FTE with a single MySQL database. Deploying independently is not a current business constraint, while distributed consistency would be expensive and would blur historical correctness. The repository therefore starts as a single Laravel application with explicit module seams.

D-08 is difficult to reverse because once modules write each other's tables or import internal models, extracting a boundary later requires data migration, contract versioning and coordinated releases.

## Decision

Adopt a modular monolith in one repository and one deployable Laravel application:

- modules live under `App\Modules\<Module>`;
- each table has exactly one owning module;
- modules may depend only on `App\Shared\*` and other modules' `Contracts\*`;
- Platform never depends on business modules;
- cross-module consistency uses Case/Decision/Effect or Outbox, never direct table writes;
- boundaries are enforced by `php artisan arch:test` and capability coverage by `php artisan capability:audit`.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Microservices per module | Forces distributed transactions or eventual consistency across payroll, time and people for an eight-person team; operational overhead outweighs deployment independence that is not required at 1–10k employees. |
| Single Laravel `app/` tree without module conventions | Fast to start, but truth ownership and dependency direction become implicit and regress silently. Directory naming alone does not enforce boundaries. |
| Modular monolith with runtime plugin loading | Adds discovery and versioning machinery before a real independent-team constraint exists; harder static analysis and debugging. |

## Consequences

Positive:

- one database transaction for local business effects;
- simple local development and CI;
- boundaries are reviewable and machine-checked;
- module extraction remains possible if a trigger is met.

Negative / costs:

- discipline is required; the scanner catches statically visible imports and raw table access, not every design leak;
- one deploy cadence and shared framework upgrades;
- cross-module reads require explicit Contracts or projections, which is slower than a direct model query.

Data, API, permission, migration, operations and training impact: table ownership is documented in `docs/01-architecture.md`; migrations remain forward-only; capability metadata is required on every non-public `/v1` route; deployment stays a single Compose stack.

## Validation

- `ModuleScannerTest` covers clean trees, cross-module model/service imports, grouped `use`, FQ names, Platform model use and cross-module raw table access.
- `ArchTestCommandTest` proves the command passes on a clean fixture and fails on a synthetic violation.
- `docs/01-architecture.md` records module ownership and the explicit allowlist (currently empty).
- CI runs `php artisan arch:test` and `php artisan capability:audit`.

## Rollback and review

Rollback path: remove the layer boundary and consolidate modules only through a new ADR; do not silently add direct cross-module model imports. Re-review when a module team needs an independent release cadence, when the single database becomes a measured scaling bottleneck, or when the organization reaches the trigger above. Owner: technical lead / architecture review.
