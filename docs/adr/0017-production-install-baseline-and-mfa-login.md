# ADR-0017: Production install baseline, real MFA login and P3 field semantics

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-20
- **Supersedes:** none; narrows ADR-0013's MFA wording and ADR-0004's field-profile wording
- **Review trigger:** the reference-data list changes, the install entry point changes, the MFA factor set changes, a new login mode (IdP/OIDC) is added, or P3 export semantics change

## Context and trigger

The only supported birth path was demo seeding: synthetic shared-password
accounts, no real TOTP factors, and reference data inserted inconsistently.
`demo.admin` was flagged MFA-enrolled with zero factors, so every step-up
failed; password alone still produced a fully usable session. Payroll reads
also returned P3 amounts without consulting the caller's field profile.

The owner confirmed: production starts with reference data + one administrator
+ zero employees; `SYSTEM_ADMIN` may see P3 amounts; login must require MFA;
and the demo dataset is optional, never default.

## Decision

1. **`hrms:install` is the only production birth path.** It is idempotent and
   creates: the legal entity, a root org unit and closure, 76 capabilities and
   10 role templates, workflow and lifecycle templates, three leave types
   (`ANNUAL`/`SICK`/`PERSONAL`, with an operator-supplied entitlement and legal
   basis), one legal-entity calendar, a `STANDARD` shift, report definitions,
   feature flags (off unless `--enable`), and one `SYSTEM_ADMIN` with a real
   confirmed TOTP factor. It creates zero employees, payroll runs and workflow
   cases. Any step failure exits non-zero and reports the failed step; already
   written rows are preserved so a rerun resumes.
2. **Reference data has independent seed commands; the installer only
   orchestrates them.** Optional data (holidays, statutory payroll parameters)
   is skipped with an explicit warning, never silently swallowed.
3. **Login really challenges MFA.** Privileged and P2+ holders receive
   `202 MFA_REQUIRED` with a one-time `challenge_id`, no session cookie and a
   5-minute expiry. Completion happens at the public, rate-limited
   `POST /v1/auth/mfa/challenge`; a wrong code does not consume the challenge
   or issue a token, and a pre-session row can never authorise a request.
4. **`mfa_enrolled_at` is a bidirectional invariant.** It is non-null exactly
   when a confirmed, non-disabled `mfa_factors` row exists.
   `iam:verify-mfa-enrollment` fails with the offending users. Seeders and the
   installer never write the timestamp without creating a real factor.
5. **MFA requirement is capability-driven.** `MfaPolicy` requires a second
   factor for privileged users and for holders of a currently effective,
   current-`auth_version` binding whose field profile is P2 or P3 (or the
   wildcard). The hard-coded payroll capability list is gone.
6. **P3 is visible to a P3 holder, never exportable or filterable.**
   `FieldSet` takes `restrictedVisible`; `CapabilityAuthorizer::fields()` sets
   it when the effective profile reaches P3. `FieldProfile::isHidden()` itself
   is unchanged, so report/export layers keep "P3 never exported". Revealing a
   P3 field writes `sensitive_field_access` with field names only.
   `/v1/payroll/runs/{id}/employees` omits money keys entirely for a
   `payroll.run.read`-only caller.
7. **The demo dataset is opt-in.** `docker_up.sh` installs the production
   baseline by default; `--demo` adds the synthetic dataset. The production
   frontend bundle is built with `VITE_API_MODE=http`; a non-http production
   build fails, and the mock adapter is never statically imported.

## Alternatives considered

- **Keep demo seeding as the default and add a production wrapper.** Rejected:
  it keeps synthetic credentials and false MFA on the default path.
- **Hard-code more payroll capabilities into the MFA check.** Rejected: the list
  drifts with the catalogue; the field profile is the durable signal.
- **Make P3 permanently hidden.** Rejected by the owner: the system
  administrator must be able to see amounts; export remains separately gated.

## Consequences

- Existing non-MFA tests that drove the real login endpoint for privileged
  users had to complete the challenge or use the explicit-principal seam. The
  MFA tests now prove "no factor ⇒ no session" instead of reaching a 403.
- `PayrollPayslipController` and the run employee list build their field set
  from the caller's actual rights; a missing grant fails closed.
- The installer is a critical path: it must remain idempotent and is covered by
  an integration test that runs it twice.
- IdP/OIDC bypass is not implemented; `MfaPolicy::challengeRequired()` is the
  replacement point.

## Validation

- `InstallCommandTest` (reference counts, one admin, one confirmed TOTP factor,
  zero business data, idempotent rerun, short password leaves no partial admin),
  `SeedCalendarCommandTest`, `BindRoleCommandTest`.
- `MfaLoginChallengeTest` (202/no cookie, correct TOTP completes, wrong code
  does not consume, expiry, pre-session cannot authorise),
  `MfaEnrollmentInvariantTest`, `MfaPolicyTest`.
- `FieldProjectionTest`, `PayrollFieldProjectionTest`, `FieldProfileTest`.
- Frontend: `adminSnapshot.spec.ts`, `pnpm typecheck/lint/test:unit/build`, the
  non-http build must fail, and `dist` must contain no `demo.example` or
  `createMockAdapter`.
