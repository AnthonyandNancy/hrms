# ADR-0004: Capability × Anchor authorization with field profiles

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-04 (Capability × Anchor × field profile, deny by default)
- **Supersedes:** none
- **Review trigger:** a route needs a data scope that cannot be expressed as one of the seven anchors, a caller needs a predicate language or `CUSTOM` anchor, or a capability must be granted without a field profile

## Context and trigger

The approved solution rejects role-based access control as the primary model, because "HR can see everything" is both too coarse (a recruiter reading payroll) and too narrow (a manager who is also an employee). D-04 fixes a three-dimensional model: a **capability** (a named action), an **anchor** (the data scope it applies to) and a **field profile** (how sensitive the returned fields are). The decision is irreversible after real bindings exist, because changing it means migrating every grant.

This reference implementation also had to answer a narrower but decisive question during integration: a route gate for a `SELF`-scoped feature must not be evaluated as if the caller's *row* were the anchor, or every legitimate `LEGAL_ENTITY`/`ORG_UNIT` holder is rejected. That produced the three-method split recorded below.

## Decision

1. **Capabilities are code-owned and frozen.** `App\Modules\IAM\Authorization\CapabilityCatalog` declares every capability with its resource, actions, allowed anchors, field profile, risk level and description. There is no editable capability surface and no client-supplied capability flag; the only superuser is a code-owned, time-boxed wildcard grant (ADR-0016). `tests/Unit/Authorization/CapabilityCatalogTest.php` asserts uniqueness, known anchors/profiles and the ten code-owned role templates.

2. **Exactly seven anchors.** `App\Shared\Authorization\AnchorType` enumerates `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `CASE`, `PAYROLL_RUN`, `LEGAL_ENTITY`. There is deliberately no `CUSTOM` variant and no arbitrary predicate language; `AnchorTypeTest` asserts the frozen set and that an unknown anchor is rejected.

3. **Three distinct questions, three methods.** `App\Shared\Authorization\Authorizer` exposes:

   - `permits(Principal, capability, businessDate): bool` — **feature gate**: does the principal hold this capability under *any* effective anchor on the business date?
   - `allows(Principal, capability, AnchorRef, businessDate): bool` — **concrete resource gate**: may the principal act on *this* anchor (this org unit, this case, this payroll run, this legal entity)?
   - `constrain(Builder, Principal, capability, businessDate): Builder` — **row scope**: push the union of allowed predicates into SQL before pagination, sorting, aggregation or export.

   The invariant is explicit in the interface docblock: `permits()` must never replace a resource-level `allows()` check, and `constrain()` narrows rows but never grants access. `RequireCapability` calls `permits()` for a bare `SELF` route gate and `allows()` when the route names an anchored parameter.

4. **Deny by default, fail closed.** `DenyAllAuthorizer` returns `false` for every capability and an empty row scope. `FailClosedAuthorizerTest` asserts unknown capabilities are denied. `DenyByDefaultTest` asserts unauthenticated requests get a 401 envelope, authenticated-but-unauthorized requests get 403, and every protected route carries capability metadata (the public allowlist is exactly `v1/health` and `v1/meta`, verified by `capability:audit`).

5. **Row scope is applied before pagination.** Reads use `constrain()` so a narrowed principal cannot page, sort or aggregate into rows outside their anchors. This is why the dashboard, employee list, report rows and exports share one builder (see ADR-0009 for export-specific rules).

6. **Field profiles P0–P3 are ordered and never merge across rows.** `FieldProfile` defines `P0_PUBLIC`, `P1_INTERNAL`, `P2_SENSITIVE`, `P3_RESTRICTED` with strict rank ordering. A row is visible only up to the profile carried by a binding that also covers that row. `P3` is always hidden, is never filterable, and is never returned by `FieldSet::mask()`. Only `P0`/`P1` are filterable; `P2` requires an export capability for export but is still masked in output.

7. **Separation of duties is layered, not a capability-set check.** Object-level invariants (the same person must not prepare and approve the same payroll run, request and approve their own break-glass, or reopen alone) are enforced by the owning service on the concrete object. Capability-set combinations are reported by `SodRules::risks()` as CUMULATIVE or OBJECT_LEVEL signals that require explicit acknowledgement and quarterly review, and are never hard-blocked, because the wildcard holder necessarily carries all of them. See ADR-0016.

8. **Route metadata is the executable policy and the audit evidence.** Capability and anchor are middleware parameters on each route; `php artisan capability:audit` compares the registered routes against `tests/Authorization/endpoint_matrix.php` and warns on missing negative-test entries. The matrix is generated from the registered route table so it cannot drift.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Role-based access control only | Too coarse (all HR sees payroll) and too narrow (a manager is also an employee); role names become the real policy and drift from the code. |
| Capability + row filter, no field profiles | A capability then grants every column, including identity numbers and salary; masking would be re-implemented per controller. |
| A `CUSTOM` anchor carrying an arbitrary SQL predicate | Unreviewable, unindexable and a direct injection surface; the audit cannot prove what it grants. |
| Permanent superuser or a client-trusted `is_admin` flag | A single compromised account becomes total compromise, and the flag is trivially forged by a client. |
| Only `allows()` (resource check) with no feature gate | Any route whose resource is the caller's own employment would require enumerating the caller's anchors per request; and using the caller's own row as the anchor for a role-scoped feature wrongly denies legitimate `LEGAL_ENTITY` holders — the exact bug this ADR records. |
| Only `permits()` (feature check) with no row scope | Every holder of a capability could read the entire tenant; IDOR and cross-entity leakage become trivial. |

## Consequences

Positive:

- least privilege is expressible: capability × anchor × field profile;
- the same predicate drives lists, drilldowns, dashboards and exports, so numbers cannot diverge;
- hidden fields cannot leak through filtering, sorting, export or logs;
- route coverage is machine-audited rather than reviewed by hand.

Negative / costs:

- three methods must be used correctly; calling `permits()` where `allows()` is required is a real vulnerability, so the interface documents the rule and reviewers must check it;
- every new route needs a capability, an anchor and a matrix entry;
- field profiles add masking and audit overhead on sensitive reads;
- bindings must be maintained as organization structure changes, and a stale binding silently removes access.

## Validation

- `tests/Unit/Authorization/AnchorTypeTest.php` — exactly seven anchors, `CUSTOM` rejected.
- `tests/Unit/Authorization/CapabilityCatalogTest.php` — unique codes, known anchors/profiles, ten role templates, Time capabilities issued by templates.
- `tests/Unit/Authorization/FailClosedAuthorizerTest.php` — unknown capability denied.
- `tests/Feature/Authorization/AnchorScopeTest.php` — all seven anchors exercised, including cross-legal-entity and cross-tenant isolation.
- `tests/Feature/Authorization/DenyByDefaultTest.php` — 401/403 envelopes and full route capability coverage.
- `tests/Feature/Authorization/FieldProjectionTest.php`, `FieldDenialHttpTest.php` — P0–P3 visibility, P2 non-filterable, missing coverage yields 404 not partial output, P3 never serialized or logged, IDOR returns 404.
- `tests/Unit/Authorization/FieldProfileTest.php`, `SodConflictTest.php` — profile ordering/masking and maker–approver conflict.
- `tests/Feature/Authorization/{MfaTest,BreakGlassTest,SessionRevocationTest,CsrfTest}.php` — step-up, two-person break-glass, immediate revocation, CSRF.
- `php artisan capability:audit`, `php artisan arch:test`.

Evidence: `evidence/logs/capability-audit.txt`, `evidence/logs/backend-test-current.txt`.

## Security and privacy

Deny by default; no hidden admin shortcut; no superuser without expiry, MFA and quarterly review; capability codes are never accepted from the client. Break-glass requires two distinct people, a reason and produces a 30-minute audited grant (`BreakGlassTest`), and the reason text is never exposed in the grant JSON. Sensitive field access is audited without logging values (`FieldProjectionTest`). Sessions carry an `auth_version`; a role, binding, password or offboarding change increments it so the next request fails immediately (`SessionRevocationTest`). Real IdP/OIDC federation, external pen-testing and production KMS remain organizational gates and are not claimed here.

## Review triggers and rollback

Revisit if a required data scope cannot be expressed with the seven anchors, if a jurisdiction requires attribute-based rules over arbitrary facts, or if binding maintenance becomes operationally unsafe at scale. Rollback is a data migration of every binding plus every stored capability in role templates, so the model is treated as irreversible once real grants exist.
