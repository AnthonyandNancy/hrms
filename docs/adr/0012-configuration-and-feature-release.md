# ADR-0012: Configuration boundary and capability/feature release control

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-19, D-24
- **Supersedes:** none
- **Review trigger:** a business concept is proposed as a new configurable dimension, a feature flag would guard a real route, a capability code or state machine is proposed for admin editing, or release/change-control evidence is required beyond the reference implementation

## Context and trigger

The approved scope is explicit: keep all original business domains but layer them
into Must/Should/Could/Won't (D-19), and govern scope/release changes with a W2
baseline, weekly CCB, one-in/one-out changes, ADR for architecture changes, and
no silent gate bypass (D-24). The dangerous failure mode is configuration drift:
once business logic is expressed as arbitrary JSON, expressions, SQL, or
admin-created capabilities, nobody can explain why an authorization or payroll
result occurred.

The repository demonstrates code-owned capability semantics, a closed workflow
template condition language, a small audited feature-flag service, and
concrete organization-move impact preview. It does not deliver a general
configuration platform, a capability release workflow UI, a configuration
inventory, or a release/CCB system. This ADR records the boundary and its gaps.

## Decision

1. **Two-layer configuration only.** Configuration may be a template plus
   parameters. The approved boundary allows leave types, shifts, payroll items and
   scheme parameters, approval nodes/candidate rules/template versions,
   dictionaries/parameters/audience, report filters/columns/export templates, and
   notification templates/reminder times. It forbids expressing capability
   semantics, anchor types, field profiles, state-machine transitions, effect
   handlers, data-scope resolution, SQL predicates, masking rules, ledger/close/
   reversal rules, identity uniqueness, cross-entity boundaries, or audit
   immutability as configuration.
2. **Capabilities and state machines are code-owned.** `CapabilityCatalog` is the
   complete V1 catalog; `CapabilityDefinition` carries resource, actions,
   anchors, maximum field profile, risk, SOD group, and description.
   `SyncCapabilitiesCommand` synchronizes the read model and ten role templates,
   refuses to remove a capability still referenced by a binding, and never
   creates semantic codes from a UI. `PayrollStateMachine`, Workflow effect
   types, and retention classes are similarly code-owned closed sets.
3. **Workflow templates use a closed declarative language.**
   `TemplateService` validates a fixed node-type set (`START`, `APPROVAL`, `CC`,
   `CONDITION`, `END`), a fixed assignee-rule set, bounded sequence/SLA values,
   and a closed effect declaration. `ConditionEvaluator` supports only
   `{field, operator, value}` leaves composed by `all`/`any`, with bounded depth
   and node count; there is no parser, `eval`, SQL, template string, or arbitrary
   expression support. Templates are tenant/version records; they do not become
   code.
4. **Feature flags are narrow release/kill switches.** `FeatureFlagService`
   has a code-owned default set (`payroll_enabled`, `time_enabled`,
   `self_service_enabled`, `recruitment_enabled`, `export_enabled`), all risky
   defaults off. Per-tenant overrides live in `feature_flags`; unknown flags are
   rejected; `EnsureFeatureEnabled` returns 404 for a disabled domain and audits
   the block. `feature:set` requires a reason of at least 8 characters and audits
   changes. The flag table is owned by Platform.
5. **Capability release is code review plus capability audit.** Adding or
   changing a capability requires a code change, a negative-test entry in
   `backend/tests/Authorization/endpoint_matrix.php`, and capability/route
   metadata. `capability:audit` fails on any non-public `/v1` route without a
   capability guard. `platform.feature.manage` is the capability guarding the
   feature-flag operational surface.
6. **Impact preview is explicit for organization moves.** The organization move
   endpoint requires an impact preview that reports affected employees,
   managers, and payroll cost centers (currently zero until Payroll cost data is
   exposed), using read-only People/Organization contracts rather than an
   ad-hoc cross-module query. The UI surfaces the preview before a move.
7. **Scope layering is a product contract, not a runtime toggle.** D-19's
   Must/Should/Could/Won't is reflected in the delivered slices and the docs,
   not in a configurable scope engine. D-24's CCB, 1-in/1-out change budget,
   W20 restriction, one-week hardening, and tech-debt budget are governance
   controls; the repository can preserve gates but cannot itself enforce a
   weekly CCB or a human sign-off.

### What is implemented vs planned

- Implemented: code-owned capability catalog and role templates; capability
  audit; feature-flag defaults, per-tenant overrides, audit, and disabled-domain
  404 behavior; closed workflow condition/template validation; organization
  impact preview; explicit must/should/could/won't scope in the approved docs.
- **Not implemented as a general platform:** a configuration inventory with
  owner/semantics/test matrix/deprecation metadata for every dimension; a
  capability-release/impact-preview workflow for new capabilities; a
  configuration change UI with versioned effective dates; a CCB/change-control
  system; automated one-in/one-out scope enforcement; a generic feature-flag
  rollout percentage or audience engine.
- **Feature flags are not wired to real domain routes in this reference build.**
  `EnsureFeatureEnabled` is registered as the `feature` alias and is tested with
  a synthetic route, but the actual payroll/time/reporting routes do not
  currently apply `feature:*`. Operational flags must not be presented as
  already gating live modules.
- The feature-flag table is written with `DB::table()` and is **not documented
  in the architecture ownership map**; `arch:test` emits warnings for
  `feature_flags` and 15 other ownership/dynamic-table findings. These are
  recorded as clean-up work, not a claim that ownership is fully resolved.
- A small inconsistency remains in the configuration vocabulary:
  `PayrollCatalogController` accepts `ANNUAL_DAYS_360` as a proration divisor,
  while `ProrationDivisor` and the calculator only implement
  `ANNUAL_DAYS_365` and `FIXED_21_75`. A client selecting the 360 option would
  fail rather than silently calculate; this input validation must be aligned
  before treating catalog configuration as production-ready.
- **Dashboard panels are pending/incomplete.** `DashboardService` renders seven
  metric codes from `reporting_projection_rows`, but `payroll_cost_by_entity`
  reports an unavailable status unless a payroll cost projection exists,
  `recruitment_funnel` is likewise unavailable in this branch, and the trend is
  hardcoded to `null`/`FLAT`. The plan's dedicated
  `backend/tests/Feature/Reporting/DashboardConsistencyTest.php` and
  `ExportScopeTest.php` do not exist in this repository; dashboard E2E coverage
  is limited to mock/real smoke checks. Dashboard panels and their same-predicate
  drill-down acceptance are therefore a known gap, not a completed D-19 Should
  slice.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Store capability, anchor, field-profile, and state-machine rules in editable tables | Makes authorization and lifecycle behavior non-deterministic, untestable, and unauditable; a database edit could silently widen access or change payroll transitions. |
| Use a generic rule engine (JSON expressions, SQL predicates, scripts) for workflow/config | Violates the approved no-arbitrary-condition boundary, creates injection and compliance risk, and makes explainability impossible. |
| Treat every feature as a database flag | Turns release state into a second program and makes it impossible to know which code paths are live; flags should be narrow, code-owned, and audited. |
| Let admins create capability codes in the UI | Bypasses code review, negative tests, route metadata, and audit semantics; `SyncCapabilitiesCommand` deliberately refuses referenced-code removal. |
| Enforce D-24 scope changes with a runtime configuration toggle | CCB approval, one-in/one-out, and release hardening are organizational decisions, not software state; a toggle cannot supply the missing owner sign-off. |
| Rely on a UI-only impact preview | A UI-only check can be bypassed by a direct API call; the organization move endpoint and contract must compute the same impact. |
| Wire all risky flags to routes immediately without a release plan | Creates hidden unavailability and a false sense of control; flags need owners, defaults, audit, and a tested rollout/kill-switch procedure. |

## Consequences

Positive:

- capability, workflow-condition, state, and retention semantics remain code
  review surfaces with negative tests and static gates;
- risky domains default off and flag changes are audited and reasoned;
- workflow templates are versioned declarative data rather than executable code;
- organization moves surface a concrete cross-module impact preview;
- D-19/D-24 scope and change-control expectations are explicit to reviewers.

Negative / costs:

- legitimate business changes require a code release rather than an admin edit;
- template/config authors are constrained to a narrow schema;
- feature flags currently exist as a service and operational surface but are not
  connected to all live domain routes;
- a configuration inventory, capability-release workflow, impact-preview for
  capabilities, and CCB tooling remain future/governance work;
- `arch:test` warnings for `feature_flags` and owned/dynamic tables must be
  resolved before claiming a clean ownership model;
- the `ANNUAL_DAYS_360` validation mismatch is a known input-boundary defect.

Data/API/permission/migration/operations/training impact: feature flags are
Platform-owned and tenant-scoped; capability/role tables are IAM-owned read
models; workflow templates are Workflow-owned data. A capability change affects
OpenAPI, capability audit, negative tests, frontend metadata, and possibly
existing authority bindings. A new configurable dimension requires an owner,
semantics, test matrix, impact preview, and deprecation plan before it enters the
product. Operations must treat a flag as a kill switch with an owner and evidence,
not as a substitute for release approval.

## Validation

- `php artisan test --filter=FeatureFlagTest` — risky defaults off and stable
  404 envelope, enabling allows the synthetic route, changes are audited and
  idempotent, unknown flags are rejected, and the update route carries
  `capability:platform.feature.manage`.
- `php artisan test --filter=CapabilityGuardTest` — unbound/unauthenticated
  requests fail closed, client headers cannot assert capability, denial is not
  written as a success audit event, and a bound capability passes.
- `php artisan test --filter=CapabilityCatalogTest` — every definition uses known
  anchors/profiles and required capability codes are present.
- `php artisan test --filter=OrgUnitWriteTest` — organization impact preview
  counts employees/managers and reports zero payroll cost centers until payroll
  data exists; move behavior preserves history.
- `php artisan test --filter=ModuleContractBoundaryTest` — the People impact
  contract is bound and tenant-scoped.
- `php artisan test --filter=TemplateVersionTest` — versioned workflow template
  creation, duplicate/version behavior, and capability metadata.
- `php artisan test --filter=WorkflowSchemaTest` — closed workflow schema and
  effect idempotency constraints.
- `php artisan capability:audit` — every non-public `/v1` route is
  capability-guarded; the recorded evidence shows 5 public allowlisted routes
  and all others guarded.
- `php artisan arch:test` — module boundary gate. The current run reports 16
  non-blocking warnings (including feature-flag ownership); this ADR records
  that as a known limitation rather than claiming full table ownership.
- `php artisan feature:set <flag> on|off --tenant=<id> --reason=<8+ chars>
  --actor=<user-id>` — audited operational override; tested in
  `FeatureFlagTest` at the service level.
- `php artisan iam:sync-capabilities` — syncs the code-owned catalog/read models
  and refuses to remove a capability code still referenced by a binding.

## Security and privacy

Capability semantics, anchor types, field profiles, and state transitions cannot
be changed by a configuration administrator; they require code review and a
negative test. Feature-flag changes require the `platform.feature.manage`
capability, a reason, and an audit event; disabled domains return 404 without
revealing whether the domain exists. Unknown flags and client-trusted capability
headers are rejected. Workflow conditions cannot execute code or SQL. Feature-flag
and configuration surfaces can affect availability, so production use needs an
owner, an explicit rollback/kill-switch runbook, and release approval (GATE).
Configuration and template changes that affect pay, access, or legal evidence
also require the corresponding finance/security/legal sign-off.

## Review triggers and rollback

Re-review before adding a new configurable dimension, before making any
capability/state/anchor/field rule data-driven, before wiring a feature flag to a
real domain route, or before exposing feature-flag administration beyond the
current capability. Also re-review when D-19 scope changes, when D-24's CCB or
gate rules change, or when a capability release needs an impact-preview UI.

Rollback path: disable the affected feature through a reviewed flag change (when
that flag is actually wired) or remove the capability from code with a migration
of authority bindings; never add a hidden admin bypass. Reverting a workflow
template requires a new version, not mutation of historical cases. A change to
the capability semantics or state machine requires a new ADR and a compatibility
plan. Owner: technical lead/release manager with security and product owners.
