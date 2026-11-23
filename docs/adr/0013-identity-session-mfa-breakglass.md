# ADR-0013: Identity, server-side sessions, CSRF, auth-version revocation, MFA and break-glass

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-09, D-10, D-11
- **Supersedes:** none
- **Review trigger:** adding OIDC/SSO or SCIM, changing cookie/CSRF/session lifetimes, making MFA optional for a privileged/payroll role, changing break-glass duration or approval, or replacing the application-key custody of MFA secrets

## Context and trigger

Every module depends on knowing who the caller is and how quickly access can be
revoked. D-09 requires a User/Person separation, OIDC-first SSO with a local
fallback, no SAML in V1, and SCIM only as an adapter. D-10 requires a server-side
HttpOnly/Secure/SameSite=Lax session cookie, CSRF on mutations, an `auth_version`
check on every request, 12h/30m standard and 8h/15m privileged limits, and
immediate revocation. D-11 requires TOTP and WebAuthn MFA, forced enrollment for
privileged/payroll roles, step-up for high-risk actions, no SMS/email second
factors, and two-person break-glass for at most 30 minutes with full audit and
no permanent super-admin.

The repository implements local-password authentication, server-side sessions,
CSRF, auth-version revocation, TOTP/WebAuthn challenges, and break-glass. It does
not implement OIDC SSO, enterprise WeChat/DingTalk/Feishu bridges, or SCIM. This
ADR records the implemented security boundary and the gaps that remain GATEs.

## Decision

1. **User is an access subject, not a Person.** `IAM\User` stores the account
   identity, status, `auth_version`, optional `person_id`, MFA enrollment marker,
   and privileged marker. Person/employment facts remain in People; IAM never
   owns employment truth. Account lifecycle is driven by Lifecycle/People
   events, and offboarding revokes access through the IAM `SessionRevoker`
   contract.
2. **Local authentication is the fallback channel in this build.**
   `AuthController::login()` accepts local username/password, uses Argon2id
   password hashing (`PasswordHasher`), records login attempts, applies a
   10-failure/15-minute lockout and a 5/min per-username+IP transient limit, and
   creates a server-side session. OIDC-first SSO is the approved primary
   channel, but it is **not implemented** here; local login remains the only
   executable login path.
3. **Server-side session with hashed tokens.**
   `SessionManager::issue()` creates a ULID session, stores only SHA-256 hashes
   of the 32-byte bearer token and CSRF token, and records IP, user agent,
   `auth_version`, idle/absolute expiry, and MFA step-up state. The response
   returns the raw token in an HttpOnly cookie and the CSRF token once in the
   JSON body; neither raw value is persisted.
4. **Cookie and CORS contract.** The cookie name is `hrms_session`; the cookie is
   HttpOnly, SameSite=Lax, path `/`, and Secure is configuration-driven
   (`IAM_SESSION_SECURE`, default true in production). CORS uses explicit
   allowed origins with credentials enabled and allows only the known headers
   including `X-CSRF-Token`, `Idempotency-Key`, and `X-Legal-Entity-Id`.
   Wildcards are not allowed with credentials.
5. **CSRF on mutations.** `VerifyCsrfHeader` exempts `GET`, `HEAD`, and
   `OPTIONS`; when the middleware is applied, every other request requires
   `X-CSRF-Token` matching the session's hashed CSRF token. Missing/invalid
   tokens return `419 CSRF_FAILED` in the standard envelope. The testing seam
   bypasses only explicit test sessions and has a real-session `CsrfTest`.
   **Current route-coverage caveat:** the `csrf.header` middleware is attached
   to the IAM group and the payroll group, but the employees, employments,
   organization, time, leave, engagement, lifecycle, workflow, platform-feature,
   recruitment, performance, training, and reporting groups are declared with
   `auth.session` only. Those groups do not yet enforce the CSRF middleware at
   the group level. This is a known security/architecture gap and must be
   resolved before claiming every mutation is CSRF-protected; the green
   `CsrfTest` proves the middleware behavior, not universal route attachment.
6. **auth-version revocation on every request.**
   `PrincipalResolver` resolves the session cookie, checks token hash,
   revocation, absolute/idle expiry, tenant binding, active user, and
   `user.auth_version === session.auth_version`. A mismatch revokes the session
   and returns 401 `AUTH_VERSION_STALE`. Role assignment and user
   update/disable paths increment `auth_version` and revoke sessions; binding
   create/revoke increments `auth_version` (the binding paths do not call
   `revokeAllForUser`, but the stale session still fails at the resolver because
   the version is checked on every request). Password change, logout-all, and
   employment offboarding also increment/revoke; the next request fails before
   authorization or query.
7. **Lifetimes.** Standard users get 30-minute idle / 12-hour absolute sessions;
   privileged users get 15-minute idle / 8-hour absolute sessions. `touch()`
   slides idle expiry but never beyond absolute expiry. `iam:reap-sessions`
   removes expired/revoked sessions.
8. **MFA TOTP and WebAuthn.** `TotpService` is a dependency-free RFC 6238
   implementation (HMAC-SHA1, 30-second step, 6 digits, ±1 window) covered by
   RFC vectors. TOTP secrets are encrypted with Laravel Crypt; recovery codes
   are SHA-256 hashed and single-use. WebAuthn registration/assertion challenge
   verification checks type, challenge, origin, `rpIdHash`, and cross-origin
   flags, and consumes the challenge once. SMS and email are explicitly rejected
   as second factors.
9. **MFA enforcement and step-up.** `RequireMfaEnrollment` blocks privileged users
   and holders of payroll prepare/approve/publish/export capabilities without a
   confirmed factor with 403 `MFA_REQUIRED`. `RequireMfaStepUp` requires a fresh
   server-side step-up (15 minutes) for the routes where `mfa.step-up` is
   attached, returning 403 `MFA_STEP_UP_REQUIRED`. The step-up middleware is
   applied to IAM administration and payroll review/approve/publish/close/
   reopen/correct/export/parameter routes; it is not a global middleware and
   must be attached explicitly to each new high-risk route.
10. **Break-glass is time-boxed and two-person.** `BreakGlassService::activate()`
    requires a distinct active approver, a reason of at least 10 characters,
    confirmed MFA for the requester, a scope limited to 10 known capabilities,
    and a 30-minute expiry. It records an audit event and an outbox alert.
    Either requester or approver may revoke. `X-Break-Glass-Grant` is checked
    against the active grant, expiry, scope, and participant; an unknown/expired
    grant does not elevate. There is no permanent super-admin switch.
11. **Capability checks remain separate from authentication.** IAM's
    `CapabilityAuthorizer` resolves capability × anchor × field profile and
    break-glass elevation; authentication never implies authorization.

### What is implemented vs not implemented

- **Implemented:** local password login and lockout/rate limiting; server-side
  hashed-token sessions; cookie/CSRF contract; auth-version revocation; standard
  and privileged session lifetimes; TOTP enrollment/step-up/recovery codes;
  WebAuthn challenge verification; forced MFA enrollment for privileged/payroll
  capability holders; step-up for high-risk routes; two-person 30-minute
  break-glass with audit/outbox/revoke/expiry; CORS with credentialed explicit
  origins.
- **Not implemented:** OIDC SSO, enterprise WeChat/DingTalk/Feishu bridges,
  SAML (deliberately out of scope), SCIM 2.0 adapter, IdP group/role mapping,
  service-account lifecycle management, and a real production IdP/SCIM
  integration. These are GATEs under D-09, not hidden features.
- **WebAuthn is a challenge verifier, not a complete credential service.** The
  repository verifies clientDataJSON type/challenge/origin/rpIdHash and stores
  no bound credential signature path; `MfaService::verifyWebAuthnStepUp()`
  verifies assertion client data and does not validate a stored COSE public-key
  assertion signature. A production WebAuthn credential service and
  attestation/signature verification are future work. This must not be
  described as production-grade WebAuthn.
- **CSRF is not attached to every mutating route group.** The `csrf.header`
  middleware appears only on the IAM group and the payroll group; the other
  route groups are `auth.session` only. The CSRF middleware itself is correct
  and tested, but universal mutation coverage is not true in this build. This is
  a security review blocker, not a documentation nuance.
- **Break-glass alerting is an outbox event plus audit, not a delivered pager or
  "full alerting" integration.** The outbox dispatcher is a log adapter in this
  reference build; real alert routing and on-call delivery are operational
  GATEs.
- **MFA secrets use Laravel application-key encryption**, not a dedicated KMS
  with rotation/split custody. Production key management is a GATE.
- **Break-glass `scope` can be `['*']`** when no scope is supplied. The service
  caps explicit scope at 10 capabilities and validates codes, but the wildcard
  is a broad emergency scope and must be reviewed against the least-privilege
  intent; it is not a permanent super-admin, but it is wider than a narrow
  capability list.
- **There is no OIDC/provider abstraction test** in this repository because the
  adapter does not exist; local fallback is the tested path.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Client-side JWT or localStorage bearer tokens | Cannot revoke a stolen token immediately, exposes credentials to XSS, and makes CSRF/session policy harder to enforce; D-10 requires a server-side session. |
| Cookie without CSRF protection | A SameSite=Lax cookie alone does not cover every cross-site mutation path; the explicit CSRF token gives a testable 419 contract. |
| Long-lived sessions without an absolute cap | Sliding idle alone lets a session live forever; server-side idle and absolute expiries are both required. |
| Role name checks instead of capability × anchor checks | Roles are packages, not authorization truth; capability/scope checks support seven anchors and field profiles. |
| SMS or email OTP as a second factor | Explicitly excluded by D-11 due to interception and account-recovery risk. |
| User-editable MFA disable or a permanent super-admin | Creates an unrecoverable or unauditable bypass; break-glass must be two-person, time-boxed, and revocable. |
| Store raw session/CSRF tokens for easier debugging | A database or log leak would immediately compromise sessions; only hashes belong at rest. |
| Implement SSO by embedding a proprietary WeChat/DingTalk SDK in core auth | Couples the core to vendor protocols; D-09 requires OIDC or an adapter/bridge outside the critical path. |

## Consequences

Positive:

- sessions are server-side, revocable, and bounded by both idle and absolute
  expiry;
- auth-version increments invalidate old sessions on the next request, including
  role/binding/password/offboarding changes;
- CSRF is explicit and testable on mutation routes;
- TOTP/WebAuthn challenge paths and recovery codes have deterministic tests;
- break-glass is two-person, time-boxed, scoped, revocable, audited, and emits an
  alert event;
- privileged/payroll roles cannot exercise the protected high-risk capabilities
  without MFA on the routes where `mfa.enrolled`/`mfa.step-up` are attached;

Negative / costs:

- local password auth is the only executable login path; OIDC/SCIM and IdP
  integration remain work and a production GATE;
- MFA secret custody and WebAuthn signature verification are not production
  complete;
- users face frequent reauthentication (especially privileged users) and
  step-up for high-risk operations;
- break-glass can be slow in an emergency because it needs a second person and
  MFA enrollment;
- outbox-only alerting is not a pager/on-call integration;
- `auth_version` and binding caches must be invalidated consistently across
  role/binding/offboarding paths.

Data/API/permission/migration/operations/training impact: IAM owns users,
sessions, MFA factors, recovery codes, authority bindings, roles, and auth
events; People/Lifecycle drive account lifecycle through contracts. Session and
CSRF configuration is environment-driven; production must use Secure cookies,
explicit CORS origins, a real IdP, MFA key custody, and alert routing. Operators
need runbooks for session revocation, break-glass approval/revocation, MFA
reset/recovery, and IdP outage. Break-glass approvers must be reachable; a
permanent super-admin is not an acceptable fallback.

## Validation

- `php artisan test --filter=LoginSecurityTest` — hardened cookie attributes,
  CSRF token once, bad password 401/no session, 5/min throttle, 10-failure
  lockout, success audit/last-login.
- `php artisan test --filter=CsrfTest` — missing/invalid CSRF returns 419 with
  envelope; GET is exempt; real-session path is tested.
- `php artisan test --filter=SessionRevocationTest` — role/binding version bump
  is simulated and next request is 401; logout-all; password change revokes
  sessions; standard 30m/12h; privileged 15m/8h; absolute expiry wins; offboarding
  invalidates old sessions.
- `php artisan test --filter=MfaTest` — privileged/payroll capability holders
  without MFA receive 403 `MFA_REQUIRED`; ordinary capability unaffected; TOTP
  enrollment/otpauth/recovery-code one-time use; TOTP step-up expires after 15
  minutes; SMS/email rejected; invalid TOTP audited.
- `php artisan test --filter=BreakGlassTest` — distinct approver, reason/active
  approver checks, 30-minute grant with audit+outbox, expiry removes elevation,
  either party can revoke, unknown grant header does not elevate, reason not
  exposed in JSON.
- `php artisan test --filter=TotpTest` — RFC 6238 SHA1 vectors, 6-digit/30-second
  behavior, ±1 window, invalid Base32/non-numeric rejection, Base32 round trip.
- `php artisan test --filter=WebAuthnChallengeTest` — random stored challenge,
  wrong challenge/origin rejection, single-use challenge, registration vs
  assertion type mismatch.
- `php artisan test --filter=IamCommandsTest` — session reaping, capability sync,
  access review, and demo-seed guardrails.
- `php artisan iam:sync-capabilities` and `php artisan iam:reap-sessions` — the
  read-model sync and session cleanup commands.
- `php artisan arch:test` — IAM boundary checks; the current run reports 16
  non-blocking warnings overall, not a zero-warning claim.

## Security and privacy

Raw session/CSRF tokens and TOTP secrets are never stored in plaintext; only
hashes/ciphertexts are persisted. Cookie attributes limit browser exposure and
CORS uses explicit credentialed origins. The CSRF middleware and real-session
test are in place, but its current route attachment is incomplete as noted
above; every request checks auth version and active user state; high-risk
routes where `mfa.step-up` is attached require a fresh step-up. Break-glass is
two-person, 30 minutes, scoped, revocable,
audited, and emits an outbox alert, with no permanent super-admin. Login
failures are rate-limited and audited without exposing credentials. Production
OIDC/SCIM, a real IdP, KMS-backed MFA/secrets, WebAuthn assertion signature
verification, full alert delivery, penetration testing, and privacy/retention
review remain GATEs. No real personal data or credentials may be used in seeds
or tests (AGENTS.md rule 8).

## Review triggers and rollback

Re-review before adding OIDC/SSO, enterprise IdP bridges, SCIM, or any change to
cookie/CSRF/session timing; before making MFA optional for a privileged or
payroll role; before changing break-glass duration, scope, or approval; before
replacing application-key MFA secret encryption; or before claiming WebAuthn is
production-ready. Also re-review if an auth-version bump path is added or
removed.

Rollback path: disable the affected high-risk endpoint/feature and revoke
sessions; do not weaken CSRF, MFA, or auth-version checks as a rollback. A
break-glass grant can be revoked by either participant and expires
automatically. Changing cookie/session/CSRF semantics requires a new ADR and a
dual-read/reauth plan; never keep a permanent bypass. Owner: security/IAM owner
with platform, HR, and audit review.
