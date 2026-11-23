# Task 3 Report — Organization and People fact kernel

## Status

**DONE_WITH_CONCERNS**

Worktree: `D:\code\ai\factoryHR\.worktrees\task-3`
Branch: `feat/task-3-people-org`
Baseline: `b869706`
HEAD at verification: `e995a48`

The feature kernel is complete and green on its baseline. Two repository-wide
gates (`capability:audit`, `arch:test`) do not exist at `b869706`; they are
Task 1B integration items and are recorded below rather than reimplemented
here. A trial run of the current composite `main` also exposed failures outside
Task 3, recorded honestly at the end.

## Commits

| Commit | Slice |
|---|---|
| `cc2433d` | Organization and Person–Employment–Assignment kernel, contracts, migrations, routes, tests, OpenAPI, data-model doc |
| `6f4e26b` | Temporal hardening: move sequencing, org-window validation, tenant-timezone directory default |
| `fbd3721` | Contract signature lock, all-profile PII projection, pagination-scope tests |
| `e995a48` | Contract-only boundary refactor: shared tenant-date seam, Organization provider, ownership architecture test |

All commits are only on `feat/task-3-people-org`, based on `b869706`.
No approved solution/plan markdown was modified.

## Delivered

### Organization module

- `Models/{LegalEntity,OrgUnit,OrgUnitClosure,Position}.php`
- `Contracts/OrganizationDirectory.php` — frozen IAM seam, exact signatures.
- Supplementary contracts `OrganizationFacts`, `LegalEntityInfo`, `OrgUnitInfo`,
  `OrgAssignmentImpact`, `DemoOrganizationSeeder`.
- `Services/{OrganizationDirectoryService,OrganizationFactsService,OrgUnitService,ClosureBuilder,TenantBusinessDate,DemoOrganizationSeederService}.php`
- `Http/Controllers/OrgUnitController.php`
- `Commands/RebuildOrgClosureCommand.php`

### People module

- `Models/{Person,Employment,Assignment,EmploymentTerms,Document,EmploymentUserLink}.php`
- `Contracts/PeopleDirectory.php` (frozen) and `EmploymentSnapshot.php`.
- `Contracts/OrgAssignmentImpactDirectory.php` so Organization never reads People tables.
- `Services/{PeopleDirectoryService,PersonService,EmploymentService,AssignmentService,TermsService,DocumentService,PersonPrivacy,AssignmentTimeline,OrgAssignmentImpactService}.php`
- `Queries/AsOfEmployeeQuery.php`
- `Http/Controllers/{EmployeeController,EmploymentController,PersonController}.php`
- `Commands/{SeedPeopleDemoCommand,PeopleAsOfCheckCommand}.php`
- `Providers/PeopleServiceProvider.php` — binds both directory contracts, the
  Clock, supplementary contracts and the fail-closed `Authorizer`.

### Migrations

- `2026_11_23_000010_create_organization_tables.php`
- `2026_11_23_000011_create_people_tables.php`

SQLite date-range triggers and MySQL/MariaDB `CHECK` constraints enforce
`valid_from < valid_to`; indexes cover every as-of predicate. There is no
`employees` table and no `current_department_id` / `current_position_id`
column.

### Shared seams

- `App\Modules\IAM\Contracts\Principal` — minimal frozen accessor set.
- `App\Shared\Authorization\{Authorizer,AnchorRef,ResourceRef,FieldSet,PrincipalResolver,DenyAllAuthorizer}` — frozen, fail-closed default.
- `App\Shared\Time\{Clock,SystemClock,FrozenClock,BusinessDate,DateRange,AsOfQuery}`.
- `App\Shared\Validation\BusinessDateRule`.
- `App\Modules\Platform\{Services/AuditService,Http/Middleware/IdempotentCommand,Exceptions/SensitiveAuditPayloadException}`.

### API

All routes are under `/v1` and every non-public one carries
`capability:<code>` middleware metadata.

```text
GET    /v1/employees
GET    /v1/employees/{employment_id}
POST   /v1/persons
POST   /v1/employments
POST   /v1/employments/{employment_id}/assignments
POST   /v1/employments/{employment_id}/terms
POST   /v1/employments/{employment_id}/documents
GET    /v1/employments/{employment_id}/timeline
POST   /v1/employments/{employment_id}/transfer
GET    /v1/documents/{document_id}/download
GET    /v1/org-units/tree
POST   /v1/org-units
POST   /v1/org-units/{id}/move
GET    /v1/org-units/{id}/impact
```

`docs/openapi/openapi.yaml` documents every route with the shared envelope,
list shape, schemas and 401/403/404/409/422 responses.
`docs/03-data-model.md` contains the text ERD, ownership map, constraints,
indexes, invariants, as-of rules, privacy tiers and known limitations.

### Tests added

Unit: `Time/{BusinessDate,DateRange,Clock,AsOfQuery}`, `People/{AssignmentOverlap,CrossLegalEntityTransfer,PersonPrivacy}`, `Organization/ClosureBuilder`, `Authorization/FailClosedAuthorizer`.

Feature: `People/{AsOfQuery,AssignmentTimeline,CrossLegalEntity,PersonDedup,TermsVersion,FieldPrivacy,FieldProfileProjection,EmploymentWrite,Timeline,IdempotencyHttp,RequirementGaps,CrossTenantIsolation,PaginationScope}`, `Organization/{OrgClosure,OrgUnitWrite,OrgUnitAsOfBoundary,ClosureHistory,OrgTenantIsolation}`, `Authorization/AuthorizationScope`.

Contract: `PeopleOrgApiContractTest`, `PeopleDirectoryFrozenSignatureTest`, `OpenApiContractTest`.

Integration: `DirectoryContractTest`, `AsOfConsistencyTest`, `ModuleContractBoundaryTest`.

Architecture: `ArchitectureBoundaryTest` — no cross-module models/tables, no
controller SQL/model writes, no current-department columns, no `whereDate`.

## Exact command results (Task 3 worktree, HEAD `e995a48`)

Commands run from `backend/` with
`/d/code/ai/factoryHR/.tools/php` prepended to `PATH`.

| Command | Exit | Result |
|---|---|---|
| `php artisan test` | 0 | **167 passed (663 assertions)** |
| `vendor/bin/pint --test` | 0 | PASS, 170 files |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 | `[OK] No errors` |
| `php artisan migrate:fresh --force` | 0 | 9 migrations |
| `php artisan people:seed-demo` (twice) | 0 | idempotent; no duplicate rows observed |
| `php artisan people:as-of-check --date=2026-11-23` | 0 | no overlap/orphan violations |
| `php artisan org:rebuild-closure --legal-entity=1` | 0 | 8 paths appended |

The 167-test total covers the Unit, Feature, Contract, Integration and
Architecture suites configured in `phpunit.xml`.

### Unavailable baseline gates

```text
php artisan capability:audit
ERROR  There are no commands defined in the "capability" namespace.

php artisan arch:test
ERROR  There are no commands defined in the "arch" namespace.
```

These commands live in Task 1B (`chore/task-1b-baseline`), which is not an
ancestor of `b869706`. Task 3 deliberately did not build competing commands.
Mitigation present in this branch:

- every new `/v1` route carries `capability:<code>` middleware metadata;
- `ArchitectureBoundaryTest` independently enforces the module/table rules;
- the Authorizer binding is `DenyAllAuthorizer`, so nothing is silently open.

## Design decisions

1. **Stable `org_units` identity, versioned closure.** A move ends all closure
   rows touching the unit at `effective_from` and appends new paths. The org row
   keeps its ID so assignments and external references never churn. Historical
   parent comes from depth-1 closure, not the mutable `parent_id`.
2. **Half-open time everywhere.** `[valid_from, valid_to)` with `9999-12-31`
   for open end, centralized in `BusinessDate`, `DateRange`, `AsOfQuery`.
3. **No denormalized current org.** Current department/position is always a
   projection over assignments for the requested business date.
4. **Fail-closed authorization seam.** Minimal frozen IAM interface, a
   deny-all default, and per-route capability metadata. Controllers call the
   `Authorizer`; list scoping happens in SQL before pagination/count.
5. **Cross-module dependency direction.** People consumes Organization via
   `Contracts\*`; Organization consumes People impact counts via a People
   contract. The demo seeder is Organization-owned and invoked by contract.
6. **PII boundary.** Identity values are encrypted with Laravel `encrypted`
   casts and deduplicated by HMAC; ciphertext, hash, file object key and SHA-256
   are hidden from serialization; audit/outbox services reject prohibited keys.
7. **Terms are append-only versions.** Model updates are constrained to
   `effective_to`; the service ends the previous version at the new
   `effective_from`.
8. **Tenant business date.** Default `as_of` uses the tenant legal entity's IANA
   timezone via the injected `Clock`; an invalid date is 422.

## Known gaps / concerns

1. **Repository-wide audit gates unavailable at baseline.** `capability:audit`
   and `arch:test` must run after Task 1B merges. No negative-test manifest was
   added because its format is owned by Task 1B; the four denial cases per route
   are partly covered (`AuthorizationScopeTest`, `FieldPrivacyTest`) and need to
   be completed when the manifest lands.
2. **HTTP capability enforcement depends on IAM Task 2.** Until IAM provides
   the real `capability` middleware alias, routes carry correct metadata but the
   default Authorizer denies all calls. Tests alias a test middleware that
   authenticates from the bound Principal and calls `Authorizer::allows`.
3. **Positions CRUD and document signed-URL issuance are modeled but not
   exposed.** `positions` and `file_objects` are referenced by assignments and
   documents; their APIs belong to later tasks.
4. **Payroll cost-center impact is always 0** until Payroll (Task 7B) exists.
5. **Transfer endpoint is the validation/contract slice.** It ends the old
   employment and creates the new one; the full approval/effect workflow is
   Task 5.
6. **Transfer employee-number collision.** If `employee_no` is omitted and the
   target legal entity already has that number, the transfer surfaces a
   database uniqueness conflict rather than a pre-validated 409. The API allows
   an explicit `employee_no`; a later workflow slice should pre-check it.
7. **Idempotency without a principal.** `IdempotentCommand` intentionally skips
   the ledger when no Principal is bound; real HTTP requests always have one,
   but a direct controller invocation in a future module must not rely on the
   ledger.
8. **Composite-main smoke run is red for reasons outside this branch.** On the
   lead's local `main` at `57c3b70`, `php artisan test` reported 84 failures:
   Time/Payroll/Migration tests; newly added `/v1/health/live`,
   `/v1/health/ready`, `/v1/platform/features*` routes are undocumented in
   OpenAPI and unguarded for `capability:audit`; IAM's provider binds test fakes
   only in `testing`; and `arch:test` flags Platform migration adapters. These
   are integration defects of the composite tree, not reproduced on
   `e995a48`, where the Task 3 scope is 167/167 green.
9. **PHPStan temp-cache quirk on Windows.** A cold run can exit 1 while
   renaming the shared result cache; an immediate rerun is `[OK] No errors`.
   `--memory-limit=1G` and a writable `TMPDIR` are required on this host.

## Merge notes for the lead

- Expected add/add conflicts in the frozen seam files (`Principal`,
  `Authorizer`, `AnchorRef`, `ResourceRef`, `FieldSet`, `Clock`,
  `FrozenClock`), `bootstrap/providers.php` and `routes/api.php`. The IAM branch
  owns the real seam shapes; adopt IAM's versions and keep this branch's
  consumers compile-clean.
- Adopt IAM's `RequireCapability` alias (`capability`) and `auth.session` +
  `csrf.header` group for these routes. This branch's tests alias `capability`
  to a test middleware and must continue to do so.
- `PeopleServiceProvider` must not re-bind `Authorizer` after IAM registers
  `CapabilityAuthorizer`. Either remove the deny-all binding at merge or ensure
  IAM's provider registers later in `bootstrap/providers.php` (IAM already
  rebinds).
- Task 1B's `ModuleBoundaryScanner` table-owner map lacks `legal_entities`,
  `employment_user_links` and `documents` nuance. Add them to Organization and
  People respectively; otherwise raw access is only a warning.
- The shared `App\Shared\Time\TenantBusinessDate` seam and
  `OrganizationServiceProvider` are now included in this branch; keep them on
  merge and delete any duplicate local copies.
- After merge, run the full gate list again; do not accept this report's green
  result as evidence for the composite tree.
