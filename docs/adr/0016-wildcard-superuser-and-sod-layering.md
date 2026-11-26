# ADR-0016: Wildcard superuser and separation-of-duties layering

> **Superseded in part by ADR-0034 (2026-11-25):** wildcard superuser and SoD layering remain; the wildcard anchor is now `COMPANY`, not `LEGAL_ENTITY`.

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-19
- **Supersedes:** ADR-0004 decision 7; the absolute "no permanent superuser" wording in ADR-0013 is narrowed to "no superuser without an expiry, MFA and quarterly review"
- **Review trigger:** a capability is added/removed, a role template gains or loses the wildcard, a new anchor type is introduced, the SoD rule catalogue changes, or a new grant API path is added

## Context and trigger

`SUPER_ADMIN` and `SYSTEM_ADMIN` previously enumerated 20/10 of 76 capabilities.
Nine capabilities were issued by no template, so the payroll approval chain was
unreachable. At the same time the capability-layer `SodRules::assertAllowed()`
hard-blocked legitimate combinations while never running on the hot path, and
could be bypassed by writing `authority_bindings` directly.

Enumerating every capability in a template drifts as the catalogue grows. A
superuser must be expressible as "all capabilities", while the one control that
must never be waived — the same person performing two steps on the same object —
must be enforced on the object, not on a capability set.

## Decision

1. **The wildcard is a first-class capability code produced only by code-owned
   templates.** `CapabilityWildcard::WILDCARD` (`*`) is the single definition.
   It never appears in the `capabilities` table and cannot be granted through
   the API: `POST /users/{id}/bindings` returns `422 WILDCARD_NOT_GRANTABLE`.
   `SUPER_ADMIN` and `SYSTEM_ADMIN` each carry exactly one `*` grant with
   `LEGAL_ENTITY` and `P3_RESTRICTED`.
2. **Wildcard bindings are time-boxed.** `iam:bind-role` enforces 30 days for
   `SUPER_ADMIN` and 366 days for `SYSTEM_ADMIN`; every binding carries
   `valid_to` and expires closed. Renewal is a new, audited grant.
3. **`*` is tolerated where the code assumes catalogue membership.** The
   capability sync excludes only the wildcard constant from its orphan check,
   the access review treats `*` as `critical`, and role-assignment validation
   never treats `*` as an unknown capability. `/v1/me/capabilities` expands the
   wildcard to the live catalogue at serialization time and reports
   `wildcard: true`; the expansion is never written back to the database.
4. **Separation of duties has two kinds and two layers.**
   - CUMULATIVE (`iam.binding.manage`×`iam.audit.read`,
     `iam.role.manage`×`iam.audit.read`,
     `people.employee.manage`×`iam.audit.read`) is job design. It is allowed
     with `acknowledge_sod_risk: true`, reported as `warnings`, audited as
     `security.sod_risk_accepted`, and listed by the quarterly access review.
   - OBJECT_LEVEL (prepare/approve, prepare/publish, review/approve on the same
     payroll run) is a control invariant. It stays a hard 409 in the owning
     `ApprovalService`, `BreakGlassService` and `ReopenService`; no wildcard
     bypasses it.
5. **`SodRules::assertAllowed()` is removed.** `SodRules::risks()` reports
   `{pair, kind}` and `risks(['*'])` returns every risk. The capability layer
   never throws a conflict.
6. **Coverage gates prevent recurrence.** `capability:audit` fails when a
   catalogued capability is issued by no template (G-COV-1), a template grants
   an unknown code other than `*` (G-COV-2), or a protected route requires an
   unissued capability (G-COV-3).

## Alternatives considered

- **Materialize 76 bindings per superuser.** Rejected: two sources of truth and
  linear row growth on every catalogue change; audit cannot distinguish a
  superuser from 76 hand grants.
- **Keep hard capability-layer SoD.** Rejected: it makes a wildcard holder
  impossible to create and still cannot see two capabilities at once.
- **Enforce object-level SoD as a capability-set rule.** Rejected: the
  invariant's scope is the object; a capability set has no object.

## Consequences

- A wildcard binding necessarily reports every CUMULATIVE and OBJECT_LEVEL risk
  in the access review. This is intentional, not a leaked conflict.
- `SYSTEM_ADMIN` now holds `iam.audit.read` through `*`; the `AUDITOR` role
  remains the independent cross-check, and the audit surface is append-only.
- A failed acknowledgement returns 422 and creates no binding; a successful one
  returns the created payload with `data.warnings` and an audit event that
  persists the risk pairs and kinds.
- The 30/366-day ceilings must be revisited if the business requires a longer
  emergency window.

## Validation

- `CapabilityWildcardTest`, `WildcardGrantTest`, `RoleTemplateCoverageTest`,
  `SyncCapabilitiesWildcardTest`, `MeCapabilitiesWildcardTest` (76 expanded
  codes, no duplicates, no DB writes).
- `SodConflictTest`, `SodRiskAcknowledgementTest` (422 without
  acknowledgement, 201 + warnings + persisted audit with acknowledgement,
  wildcard API rejection), and the real object-level self-approval tests in
  `PayrollRunLifecycleTest` at domain and HTTP level.
- `CapabilityCoverageAuditTest` and `capability:audit`.
