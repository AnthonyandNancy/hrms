# ADR-0018: Password-only sign-in is the accepted operating mode; MFA is off by default

- **Status:** Accepted — 2026-11-25
- **Decision IDs:** D-21
- **Supersedes:** ADR-0017 decision 3/5 (MFA required at login and for P2/P3 holders); ADR-0013's MFA-at-login requirement remains implemented but is no longer the default
- **Review trigger:** the system is exposed beyond the trusted internal network, a privileged account is suspected compromised, an audit/insurer/customer requires MFA, or `IAM_MFA_ENABLED` is flipped to true

## Context and trigger

The system was built with real TOTP at login and step-up. The owner has now
decided that this operating mode is too cumbersome: a correct username and
password must be sufficient to enter the system, without a dynamic code. This
is an explicit, accepted reduction of the authentication control; it must be
recorded rather than silently weakened.

## Decision

1. **`IAM_MFA_ENABLED` defaults to `false`.** It is read through
   `config('iam.mfa.enabled')` only (never `env()` in application code).
2. **When disabled:**
   - `MfaPolicy::reason()` returns `null`, so login never returns
     `202 MFA_REQUIRED`;
   - `RequireMfaEnrollment` and `RequireMfaStepUp` pass through;
   - `/v1/me/capabilities` reports `mfa_required: false`;
   - `hrms:install` does not create or require a TOTP factor and records
     `administrator_mfa = SKIPPED`;
   - the login page and account menu hide the MFA entry points.
3. **Nothing is deleted.** TOTP enrollment, step-up, recovery codes, the
   challenge table and `iam:verify-mfa-enrollment` remain in place. Setting
   `IAM_MFA_ENABLED=true` restores the previous behaviour without a migration.
4. **Password hygiene still applies.** Login rate limiting, account lockout,
   `auth_version` session invalidation, HttpOnly/CSRF cookie handling and the
   capability gate are unchanged.
5. **This is not a claim of equivalent security.** The risk is accepted by the
   owner for the current internal deployment; the review triggers above must
   cause this ADR to be revisited.

## Alternatives considered

- **Delete the MFA code.** Rejected: re-enabling would require rebuilding the
  challenge flow, migrations and tests; a switch keeps the capability.
- **Keep MFA for privileged users only.** Rejected by the owner for now:
  "账号密码就能进系统" applies to the administrator as well; the switch keeps
  that option available through configuration, not code.

## Consequences

- A stolen password is sufficient to sign in, including as `SYSTEM_ADMIN`.
  Compensating controls are network restriction, rate limiting, lockout,
  short session lifetimes, `auth_version` revocation and audit logging.
- The MFA tests now opt in with `config(['iam.mfa.enabled' => true])`; new
  `PasswordOnlyLoginTest` proves the default path.
- The installer no longer prints a TOTP secret or recovery codes by default;
  if an operator wants them, they must enable MFA and enrol explicitly.

## Validation

- `PasswordOnlyLoginTest` (privileged user with a real factor still logs in
  with password only; `/me/capabilities` reports `mfa_required: false`).
- Existing MFA regression suites run with `iam.mfa.enabled = true`:
  `MfaLoginChallengeTest`, `MfaTest`, `MfaPolicyTest`,
  `MfaEnrollmentInvariantTest`.
- `InstallCommandTest` asserts no factor and a null `mfa_enrolled_at` by
  default, preserving the bidirectional invariant (no fake enrollment).
