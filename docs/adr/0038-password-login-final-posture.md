# ADR-0038: Password Login Is the Final Posture; MFA Is Optional

- Status: Accepted — 2026-11-26
- Date: 2026-11-26
- Approval: 用户 2026-11-26 批准密码登录为最终姿态、MFA 默认关闭
- Related: ADR-0018, ADR-0025

## Decision

1. Username plus password is the only required login flow.
2. `IAM_MFA_ENABLED` defaults to false and is the production acceptance setting.
3. MFA middleware must pass through when disabled without bypassing capability checks.
4. Password hashing, rate limiting, lockout, session, CSRF, revocation and audit remain.
5. MFA/WebAuthn code may remain but is hidden from the main login UI.

## Validation

- Password-only login test proves no 202/403 for privileged users.
- Disabled MFA tests cover admin, HR and payroll APIs.
