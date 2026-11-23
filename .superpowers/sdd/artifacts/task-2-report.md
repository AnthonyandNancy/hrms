# Task 2 — IAM: identity, sessions, capabilities, anchors, field projection

Status: **complete for the reference implementation scope**.
Worktree: `.worktrees/task-2`, branch `feat/task-2-iam`, baseline `b869706`.

## Deliverables

### Data model

- `backend/database/migrations/0001_01_01_000000_create_users_table.php`:
  tenant-scoped access subjects with `username`, nullable email/password,
  `status`, `auth_version`, `person_id`, `mfa_enrolled_at`, `is_privileged`,
  `last_login_at`.
- `backend/database/migrations/2026_11_23_100000_create_iam_tables.php`:
  `sessions`, `mfa_factors`, `recovery_codes`, `capabilities`, `roles`,
  `role_capabilities`, `user_roles`, `authority_bindings`, `auth_events`,
  `break_glass_grants`, `login_attempts`.
  - ULID session IDs, BIGINT business PKs, UTC timestamps.
  - `authority_bindings.anchor_key` normalises null anchors to `0` so the
    unique key is deterministic on SQLite and MySQL.
- Models under `backend/app/Modules/IAM/Models/*`.
  Session and MFA secrets are hidden; `AuthEvent` blocks update/delete.
- Factories: `UserFactory`, `AuthorityBindingFactory`, `SessionFactory`.

### Authorization kernel (`backend/app/Shared/Authorization`)

- `AnchorType` — exactly `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`,
  `CASE`, `PAYROLL_RUN`, `LEGAL_ENTITY`; no `CUSTOM`.
- `FieldProfile` — `P0_PUBLIC`, `P1_INTERNAL`, `P2_SENSITIVE`,
  `P3_RESTRICTED` with ordered rank and filterability rules.
- `FieldSet` — visible/filterable/exportable/hidden, `mask()`,
  `assertFilterable()` / `assertVisible()` domain 422s. Hidden keys are
  removed before masking, so P3 never serialises.
- `AnchorRef`, `ResourceRef`, `EmploymentRef`, `BindingGrant`,
  `SodRules`, `SodConflictException`, `Authorizer` interface.
- Ports in `Contracts`: `PersonEmploymentDirectory`,
  `OrganizationScopeDirectory`, `CaseMembershipDirectory`,
  `PayrollRunMembershipDirectory`, plus `AuthorityBindingRepository`,
  `AccessSubjectStateDirectory`, `ElevationGrantDirectory`,
  `SensitiveAccessAuditor`, `AnchorResolver`, `ScopeableResource`.
  IAM never imports People/Organization/Workflow/Payroll models or services.

### IAM implementation (`backend/app/Modules/IAM`)

- `Authorization/CapabilityCatalog` — 38 code-owned definitions (all required
  codes plus intrinsic `iam.session.manage`), unique codes, valid anchors,
  risk, SOD group and max field profile.
- `Authorization/RoleTemplateCatalog` — exactly ten templates:
  `SUPER_ADMIN`, `SYSTEM_ADMIN`, `HR_ADMIN`, `HR_SPECIALIST`, `RECRUITER`,
  `PAYROLL_SPECIALIST`, `ATTENDANCE_SPECIALIST`, `LINE_MANAGER`, `EMPLOYEE`,
  `AUDITOR`; structural role-code validation.
- `Authorization/EloquentAuthorityBindingRepository` — tenant + principal +
  capability + `auth_version` + effective-date lookup with a short cache.
- `Authorization/EloquentAccessSubjectStateDirectory` and
  `EloquentElevationGrantDirectory` — live status/auth-version and active
  break-glass checks.
- `Authorization/PlatformSensitiveAccessAuditor` — `sensitive_field_access`
  audit with field names only.
- `Auth/CapabilityAuthorizer` — default deny, SQL row predicates via
  `constrain()` (no post-filtering), union across bindings, per-row field
  profile resolution, SOD hard-deny, tenant and anchor checks, sensitive
  access audit.
- `Auth/PrincipalResolver`, `AuthenticatedPrincipal`,
  `AuthenticationContext` — session cookie to principal with per-request
  idle/absolute/revoked/status/auth-version checks.
- HTTP:
  - `AuthController` — login (Argon2id, rate limit, lockout, audit), logout,
    logout-all, change-password.
  - `MeController` — `/me`, `/me/capabilities`.
  - `UserAdminController` — users list/create/status, role assignment with
    explicit anchors and SOD pre-check.
  - `AuthorizationAdminController` — roles, read-only capabilities, bindings,
    auth events.
  - `MfaController` — TOTP enroll/confirm, step-up, WebAuthn options.
  - `BreakGlassController` — activate/revoke.
  - Middleware: `auth.session`, `csrf.header`, `capability`,
    `mfa.enrolled`, `mfa.step-up`.
- Services:
  - `SessionManager` — 32-byte bearer + CSRF tokens, SHA-256 at rest,
    30 min/12 h standard and 15 min/8 h privileged windows, sliding idle
    capped by absolute expiry.
  - `PasswordHasher` — Argon2id only; weaker hashes rejected.
  - `SecurityEventService` — append-only auth evidence with allow-listed
    metadata; tokens/secrets never recorded.
  - `TotpService` — dependency-free RFC 6238 HMAC-SHA1, 30 s step, ±1
    window, Base32, otpauth URI.
  - `WebAuthnService` — random single-use challenge, type/challenge/origin/
    `rpIdHash` verification; signature success is not faked.
  - `MfaService` — encrypted TOTP secrets, one-time recovery codes,
    step-up 15 min, SMS/email rejected, MFA requirement detection.
  - `BreakGlassService` — two distinct people, reason >=10, MFA required,
    30-minute expiry, audit + outbox, either-party revocation.

### Routes and config

- `backend/routes/api.php`: public allowlist is `v1/health`, `v1/meta`,
  `v1/auth/login`. Every other `/v1` route carries `capability:<code>`
  metadata; high-risk mutations add `mfa.enrolled` and `mfa.step-up`.
- `backend/bootstrap/app.php`: middleware aliases.
- `backend/config/iam.php`: session, login, MFA, break-glass, demo settings.
- `backend/config/auth.php`: IAM `User` model.

### Commands

- `iam:sync-capabilities` — syncs code catalog + ten role templates; fails if
  a removed code is still referenced.
- `iam:seed-demo` — idempotent synthetic demo users, requires
  `DEMO_SEED_PASSWORD` >=12 chars, no hard-coded password.
- `iam:reap-sessions` — revokes expired sessions.
- `iam:access-review --quarter=YYYY-Qn` — JSON of high-risk bindings unused
  >90 days and SOD conflicts.
- Deliberately did **not** create `capability:audit` or `arch:test`; Task 1B
  owns those commands.

### Tests

- `backend/tests/Authorization/endpoint_matrix.php` — 22 IAM endpoints with
  denial-case entries (unauthenticated, no capability, wrong anchor/grant,
  field denial; plus CSRF, IDOR, stale auth version, MFA, SOD, cross-entity
  where relevant).
- `backend/tests/Unit/Authorization/` — AnchorType, FieldProfile,
  CapabilityCatalog (unique codes/10 templates), SOD rules.
- `backend/tests/Unit/IAM/` — RFC 6238 official vectors, WebAuthn challenge
  verification, command behavior.
- `backend/tests/Feature/Authorization/` — deny-by-default, all seven
  anchors, field projection and HTTP field denial, CSRF, session revocation
  and idle/absolute timeouts, MFA, break-glass, IDOR, concurrency,
  role-anchor assignment and login hardening.
- `backend/tests/Contract/` — IAM OpenAPI fragment + route/matrix coverage.

### Docs

- `docs/02-permissions.md` — capability matrix, ten role templates, anchor
  rules, field profiles, session/MFA/break-glass parameters and the negative
  test matrix.
- `docs/openapi/iam.yaml` — OpenAPI 3.1 IAM fragment (21 paths, session +
  CSRF security schemes, envelopes). The canonical
  `docs/openapi/openapi.yaml` is owned by Task 1B and is not present on this
  branch, so this fragment must be merged by the coordinator.

## Commands and exact results

Run from `backend/` with portable PHP on PATH.

```
php artisan test
Tests: 123 passed (931 assertions), Duration ~15 s

vendor/bin/pint --test
PASS 169 files

vendor/bin/phpstan analyse --no-progress --memory-limit=1G
[OK] No errors
```

`vendor/bin/phpstan analyse` needs `--memory-limit=1G`; the default 128 MB
PHPStan worker limit crashes on this codebase. No source issue causes this.

`capability:audit` and `arch:test` are absent on this branch because Task 1B
owns them. Their intended checks are covered locally by
`tests/Contract/IamOpenApiContractTest` (route capability metadata) and
`tests/Contract/IamRouteMatrixTest` (every IAM route has a matrix row).

## Design decisions

1. **Single binding resolves row and fields.** Field profiles are never
   merged across bindings that do not cover the row; the maximum covering
   profile wins.
2. **SQL push-down.** `constrain()` builds `where`/`whereIn`/`whereRaw('1=0')`
   unions before pagination. No user text reaches SQL.
3. **Intrinsic self-service capability.** `iam.session.manage` is granted by
   middleware only when the principal has a server-side session; it is not a
   role grant and cannot be delegated.
4. **`anchor_key` instead of nullable uniqueness.** Null anchors become `0`
   so the unique key behaves identically on SQLite and MySQL.
5. **Auth version is the revocation primitive.** Every binding/role/password/
   status/MFA enrollment change increments it in the same transaction; the
   old session cannot be served from a cached binding set.
6. **MFA step-up is server-side.** `mfa_verified_until` lives on the session;
   middleware checks it with the injected clock. SMS/email are rejected
   before any factor lookup.
7. **Break-glass is time-boxed.** A grant is a row with 30-minute expiry,
   explicit scope, reason (hidden from JSON), two distinct users, audit and
   outbox evidence; there is no permanent super-admin flag.
8. **Ports and fakes.** Cross-module reads go through narrow interfaces with
   PSR-4 test fakes; the production bindings arrive with Tasks 3/4/7.

## Known gaps / merge notes

- No real IdP/OIDC, SCIM, WebAuthn signature verification or enterprise
  notification transport. The brief only requires the WebAuthn contract and
  challenge verification; signature verification is documented as future
  credential work.
- People/Organization/Workflow/Payroll adapters are fakes in `testing`;
  production must bind real implementations from those modules.
- The canonical OpenAPI file and root architecture tests belong to Task 1B;
  `docs/openapi/iam.yaml` is a merge fragment, not a second source of truth.
- Additive shared edits for the merge coordinator: `backend/routes/api.php`,
  `backend/bootstrap/app.php`, `backend/bootstrap/providers.php`,
  `backend/config/auth.php`, `backend/database/seeders/DatabaseSeeder.php`,
  `backend/app/Providers/AppServiceProvider.php`, `docs/02-permissions.md`,
  `docs/openapi/iam.yaml`.
- PHPStan must run with `--memory-limit=1G` (see above).

## Commits

```
15e0f24 feat(iam): add code-owned capability catalog and seven-anchor authorizer
558da6f feat(iam): add authenticated sessions, CSRF enforcement and route capability gates
d5f5ea9 test(iam): cover session revocation, CSRF and IDOR denial paths
d9bbc01 feat(iam): add RFC6238 MFA, WebAuthn challenge verification and two-person break-glass
7efdbd1 feat(iam): add catalog sync, demo seed, session reaper and access-review commands
2e3e659 chore(iam): enforce pint and phpstan quality gates
16df471 feat(iam): enforce fresh MFA step-up on high-risk mutations
a18950b fix(iam): route all security timestamps through the injectable clock
5136318 feat(iam): require explicit role anchors and validate structural role codes
fe88086 docs(iam): document capability matrix, role templates, denial matrix and IAM API fragment
dc6ffc6 test(iam): assert OpenAPI fragment and route capability metadata
c6d7bb6 test(iam): cover login cookie hardening, throttling, lockout and audit
```
