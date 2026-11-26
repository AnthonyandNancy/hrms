# ADR-0038: Password Login Is the Final Posture; MFA Is Optional

- Status: Accepted — 2026-11-26
- Date: 2026-11-26
- Approval: 用户 2026-11-26 批准密码登录为最终姿态、MFA 默认关闭
- Related: ADR-0018, ADR-0025

## Context

ADR-0018 accepted password login, while ADR-0025 and parts of V1.1 still
presented MFA as a production prerequisite for high-privilege accounts. That
wording forces an enrollment and challenge flow into an intranet deployment
where the user has decided password login is the final posture, and it creates
acceptance failures for admin, HR and payroll APIs when `IAM_MFA_ENABLED=false`.
The MFA implementation already exists and should not be deleted, but it must not
gate login or authorization.

## Decision

1. Username plus password is the only required login flow.
2. `IAM_MFA_ENABLED` defaults to false and is the production acceptance setting.
3. MFA middleware must pass through when disabled without bypassing capability checks.
4. Password hashing, rate limiting, lockout, session, CSRF, revocation and audit remain.
5. MFA/WebAuthn code may remain but is hidden from the main login UI.

## Alternatives

- Keep mandatory MFA for high-privilege accounts: rejected; it contradicts the approved intranet password-only posture and turns optional security infrastructure into an acceptance blocker.
- Delete MFA, WebAuthn, step-up and break-glass code: rejected; it removes a working defence-in-depth option and makes future enablement a rebuild.
- Keep MFA enabled by default but allow accounts to skip enrollment: rejected; login would still branch on MFA state and acceptance would depend on deployment configuration.
- Replace MFA with SSO/IdP as the prerequisite: rejected; no IdP exists on the intranet and it is outside this delivery.

## Consequences

- Username plus password logs privileged users in directly; no 202/403 or enrollment detour when MFA is disabled.
- Security compensation shifts to password hashing, rate limiting, lockout, session and CSRF controls, revocation, least privilege and audit.
- MFA remains available for administrators at `/account/security`, and enabling `IAM_MFA_ENABLED=true` restores challenge/step-up without code changes.
- Tests and seed flows must not assume MFA enrollment; optional-MFA paths need coverage so a disabled middleware passthrough cannot bypass capability checks.
- The production acceptance setting is explicitly documented, reducing configuration drift between environments.

## Validation

- Password-only login test proves no 202/403 for privileged users.
- Disabled MFA tests cover admin, HR and payroll APIs.

## Review trigger

The system is exposed beyond the intranet, remote or third-party operations are required, administrator accounts grow beyond five or are shared, a credential incident occurs, or a compliance baseline mandates MFA.
