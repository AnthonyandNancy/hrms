# Task 2 — IAM: identity, sessions, capabilities, anchors, field projection

## Context

Build on the Platform baseline (Task 1): response envelope, request-id, audit, outbox, idempotency, module boundaries and capability audit already exist at `backend/`. Read `.superpowers/sdd/artifacts/interface-freeze.md` for binding interfaces and namespaces.

Authoritative sources: solution §7 (incl. 7.11) and plan §4.2, §5.2, §7. This task must make "default deny, code-owned capability, 7 anchors, 4 field profiles, immediate revocation" real and testable.

## Data model (migrations, models)

Use BIGINT unsigned PKs, `tenant_id`, UTC timestamps.

- `users`: id, tenant_id, username unique per tenant, email nullable, password_hash nullable (local fallback), status (`ACTIVE|LOCKED|DISABLED`), auth_version bigint default 1, mfa_enrolled_at nullable, is_privileged bool, last_login_at, created_at, updated_at.
- Users are access subjects, not natural persons: `users.person_id` nullable (no FK until People migrates).
- `sessions`: id (ULID string), user_id, tenant_id, token_hash (sha256, never raw), csrf_hash, ip, user_agent, mfa_verified bool, auth_version, idle_expires_at, absolute_expires_at, revoked_at nullable, last_seen_at, created_at.
- `mfa_factors`: id, user_id, type (`TOTP|WEBAUTHN`), secret_ciphertext nullable, credential_id nullable, public_key nullable, label, confirmed_at, disabled_at.
- `recovery_codes`: user_id, code_hash, used_at.
- `capabilities`: code (PK string up to 120), resource, actions_json, anchor_types_json, field_profile_max, risk (`low|medium|high|critical`), sod_group nullable, description, synced_at. Semantics are code-owned; table is a synced catalog for FK/UI/audit. No API to create/edit.
- `roles`: code, name, is_template bool, owner nullable, purpose, expires_at nullable. Seed 10 templates: SUPER_ADMIN, SYSTEM_ADMIN, HR_ADMIN, HR_SPECIALIST, RECRUITER, PAYROLL_SPECIALIST, ATTENDANCE_SPECIALIST, LINE_MANAGER, EMPLOYEE, AUDITOR. No person/department names in role codes; validation rejects them.
- `role_capabilities`: role_code, capability_code, anchor_type default, field_profile.
- `user_roles`: user_id, role_code, valid_from, valid_to, granted_by, reason.
- `authority_bindings`: id, tenant_id, principal_id (user_id), capability_code, anchor_type, anchor_id nullable, field_profile, purpose_code, valid_from, valid_to, source_type, source_id, auth_version, created_at. Unique `(principal_id, capability_code, anchor_type, anchor_id, valid_from)`; handle NULL anchor_id deterministically.
- `auth_events`: append-only login/logout/failure/mfa/step-up/break-glass/revocation.
- `break_glass_grants`: id, tenant_id, requester_user_id, approver_user_id, reason, granted_at, expires_at (30 minutes), revoked_at, scope_json, alert_sent_at.

## Capability catalog (code-owned)

`App\Modules\IAM\Authorization\CapabilityCatalog` returns value objects. Register at least:
- `iam.user.read`, `iam.user.manage`, `iam.role.manage`, `iam.binding.manage`, `iam.audit.read`
- `org.unit.read`, `org.unit.manage`, `org.tree.read`
- `people.employee.read`, `people.employee.manage`, `people.assignment.manage`, `people.contract.read`, `people.contract.manage`
- `workflow.case.read`, `workflow.case.create`, `workflow.case.decide`, `workflow.case.admin`
- `time.attendance.read`, `time.attendance.manage`, `time.leave.read`, `time.leave.request`, `time.leave.admin`
- `payroll.run.read`, `payroll.run.prepare`, `payroll.run.review`, `payroll.run.approve`, `payroll.run.publish`, `payroll.payslip.read`, `payroll.payslip.read.self`, `payroll.export`
- `talent.recruitment.manage`, `talent.performance.manage`, `talent.training.manage`
- `engagement.notice.read`, `engagement.notice.manage`
- `reporting.dashboard.read`, `reporting.export`

`AnchorType` enum: SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, CASE, PAYROLL_RUN, LEGAL_ENTITY. Reject any other value. No `CUSTOM`, no arbitrary SQL, no condition-JSON column anywhere.

`FieldProfile` enum: P0_PUBLIC, P1_INTERNAL, P2_SENSITIVE, P3_RESTRICTED with ordered rank. `FieldSet` value object: `visible`, `filterable`, `exportable`, `mask(array $row): array`. P3/HIDDEN fields never serialized; filtering or sorting by a non-filterable field throws domain 422.

## Authorization engine

Implement in `App\Shared\Authorization`:
- `Authorizer` per frozen contract.
- `AnchorResolver` interface with SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, CASE, PAYROLL_RUN, LEGAL_ENTITY implementations, using injectable `PeopleDirectory`, `OrganizationDirectory`, `CaseMembership`, `PayrollRunMembership` contracts. Provide test fakes; do not directly query other module tables.
- Resolve bindings valid at `businessDate` (inclusive start, exclusive end) and matching principal `auth_version`.
- Row scope = union across matching bindings. Field profile = maximum profile among bindings covering that specific row; never combine row scope from one binding with fields from an uncovered binding.
- Hard-deny precedence: disabled/locked user, stale auth_version, tenant mismatch, SOD conflict, expired binding.
- `constrain()` pushes predicates into SQL (`whereIn`/`whereExists`), never post-filters paginated results.
- Cache bindings per principal+auth_version with short TTL; invalidation increments auth_version.
- `FieldProjector`: mask rows by profile; audit `sensitive_field_access` when P2/P3 is returned.

## Authentication and sessions

- `POST /v1/auth/login` (username/password), rate limit 5/min per username+IP, lock after 10 failures in 15 min, Argon2id verification.
- Session cookie: HttpOnly, Secure config-driven, SameSite=Lax. Random 32-byte token; store hash only. Rotate on login and privilege change.
- Timeouts: standard 30 min idle / 12 h absolute; privileged 15 min / 8 h. Enforce each request; expired => 401 and revoke.
- CSRF: state-changing requests require `X-CSRF-Token` matching session hash.
- Every authenticated request verifies `auth_version`; mismatch => 401 + revoke.
- `GET /v1/me`; `GET /v1/me/capabilities`; `POST /v1/auth/logout`; `POST /v1/auth/logout-all` (increments auth_version); `POST /v1/auth/password` (requires current password, increments auth_version, revokes other sessions).

## MFA and break-glass

- `POST /v1/auth/mfa/totp/enroll` returns otpauth URI + secret once; `POST /v1/auth/mfa/totp/confirm` verifies 6-digit RFC 6238 code using dependency-free base32 + HMAC-SHA1, 30 s step, ±1 window. Encrypt secret at rest.
- `POST /v1/auth/mfa/step-up` marks `mfa_verified` for 15 minutes.
- WebAuthn: provide registration/assertion service contracts and unit-tested challenge verification. Do not fake success.
- Privileged users and payroll prepare/approve/publish holders must enroll MFA; otherwise 403 `MFA_REQUIRED`.
- Break-glass: `POST /v1/auth/break-glass` requires requester + distinct approver user id + reason >= 10 chars, creates 30-minute grant, emits `security.break_glass.activated` audit and outbox event. Expired grants invalid automatically; actions carry grant id. `POST /v1/auth/break-glass/{id}/revoke` allows either party to revoke. No permanent super-admin.

## Endpoints and protection

All `/v1` routes except health/meta/login/break-glass bootstrap carry `capability:` middleware metadata. `RequireCapability` middleware resolves `Authorizer::allows` for the route anchor; 403 with reason code; audit sensitive access.

Admin endpoints:
- `GET /v1/users`, `POST /v1/users`, `PATCH /v1/users/{id}` (status), `POST /v1/users/{id}/roles`
- `GET /v1/roles`, `GET /v1/capabilities`
- `GET /v1/users/{id}/bindings`, `POST /v1/users/{id}/bindings`, `DELETE /v1/bindings/{id}`
- `GET /v1/auth/events`

## Commands

- `php artisan iam:sync-capabilities` — sync code catalog; fail if removing a code still referenced.
- Extend `php artisan capability:audit`: every protected route has capability metadata and catalog entry; warn on missing negative-test fixture.
- `php artisan iam:reap-sessions`.
- `php artisan iam:access-review --quarter=YYYY-Qn` — JSON of high-risk bindings unused >90 days and SOD conflicts.
- `php artisan iam:seed-demo` — idempotent demo users (admin/hr/manager/employee), env `DEMO_SEED_PASSWORD`, no hardcoded production password.

## Tests (must pass)

Feature:
- `DenyByDefaultTest`: 401 unauthenticated; 403 without capability; route without metadata fails audit.
- `AnchorScopeTest`: all 7 anchors; MANAGED follows manager assignment; cross-tenant denied; legal entity isolation.
- `FieldProjectionTest`: P0 visible/filterable; P1 mask; P2 masked/not filterable; P3 hidden; export flag; no hidden field in JSON/logs/query.
- `SessionRevocationTest`: role/binding change increments auth_version => next request 401; logout-all; password change revokes others; idle/absolute expiry via fake clock; 15/8 h privileged.
- `MfaTest`: RFC 6238 vectors; privileged without MFA => 403 MFA_REQUIRED; step-up expires in 15 min; recovery code single-use; SMS/email rejected.
- `BreakGlassTest`: two distinct people; reason; 30-min expiry; audit + outbox; revoke; grant id on actions.
- `CsrfTest`: missing/invalid token => 419/403 envelope; GET unaffected.
- `IdorTest`: user A cannot read B; 404.
- `ConcurrencyTest`: parallel binding changes produce monotonic auth_version, no lost updates.
Unit: CapabilityCatalogTest (unique codes, valid anchors, profile ordering), FieldProfileTest, AnchorTypeTest (reject CUSTOM), SodConflictTest.
Contract: `/v1/me`, `/v1/me/capabilities`, login/logout match OpenAPI.

## Constraints

- No `condition_json`, no CUSTOM anchor, no standalone FieldPolicy/Ownership tables.
- No raw SQL interpolation from user input.
- No secrets/tokens/passwords in logs or audit payloads.
- Argon2id; session token SHA-256 stored; all new PHP `declare(strict_types=1)`.
- Update `docs/openapi/openapi.yaml` and `docs/02-permissions.md` (capability matrix, 10 role templates, negative test matrix).
- Each endpoint has 4 denial classes (unauthenticated, no capability, wrong anchor, field denial); high-risk 8.
- Show `php artisan test`, pint, phpstan, capability:audit green in the report.

## Report

Write `.superpowers/sdd/artifacts/task-2-report.md` with files, commands + exact results, design decisions, known gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
