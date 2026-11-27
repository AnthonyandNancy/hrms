# Task 10 — 密码登录与可选 MFA 验收报告

## Status

**Implemented, tested and committed.** Username + password is the only
required login flow; MFA remains an opt-in defence-in-depth control and is off
by default. No routes, migrations, schema, OpenAPI or other Volume 2 task files
were modified.

## Commit

`d361324` — `feat(auth): enforce password-only login posture with optional MFA`

Files committed (7):

- `backend/config/iam.php`
- `backend/app/Modules/IAM/Console/Commands/InstallCommand.php`
- `backend/tests/Feature/Authorization/PasswordOnlyLoginTest.php`
- `backend/tests/Feature/Authorization/MfaDisabledApiAccessTest.php` (new)
- `frontend/src/views/LoginView.vue`
- `frontend/src/modules/platform/pages/AccountSecurityPage.vue`
- `frontend/tests/unit/LoginView.spec.ts` (new)

## Requirements verification (against the real code)

1. **Default switch.** `iam.mfa.enabled` is `(bool) env('IAM_MFA_ENABLED', false)`.
   `MfaPolicy::reason()` returns `null` when the switch is off, so the login
   controller never enters the challenge branch. `RequireMfaEnrollment` and
   `RequireMfaStepUp` each return `$next($request)` before any enrollment or
   freshness decision. Nothing else in the login path reads MFA state.
2. **`POST /v1/auth/login` shape.** Validation remains only
   `username` + `password`. A privileged user with a real, encrypted TOTP
   factor receives `200` with a session cookie, never `202`/`403`; a request
   carrying `mfa_code`/`captcha` is still a normal password login.
3. **No capability bypass.** With MFA disabled the enrollment and step-up
   middleware pass through, but the later `capability` middleware still runs.
   Tests prove `GET /v1/users` (read, `mfa.enrolled` only) and
   `POST /v1/users` (write, `mfa.enrolled` + `mfa.step-up`) both return
   `403 CAPABILITY_DENIED` for an authenticated user without the capability,
   and that the write creates no row.
4. **Installer.** In the disabled branch the command no longer queries factors,
   generates no TOTP, prints no secret/recovery codes and does not set
   `mfa_enrolled_at`; it records
   `administrator_mfa=SKIPPED (IAM_MFA_ENABLED=false)`. The opt-in branch is
   unchanged. `InstallCommandTest` (5 tests) still asserts no factor and a null
   enrollment timestamp by default.
5. **Login page.** Renders only username, password and the login button in the
   password step. The MFA-enrollment entry point (dialog, `mfa=enroll` query
   handling, "绑定动态口令" button) is removed. A challenge step can still
   render only after an explicit `IAM_MFA_ENABLED=true` backend response, so
   the opt-in flow is not broken.
6. **Account security.** The page keeps optional TOTP enrolment available and
   now states explicitly that password login is the only required flow and MFA
   is optional. No "must enrol" wording was added.
7. **Preserved controls.** No changes to `AuthController`, password hashing,
   rate limiting, lockout, session issuance, CSRF, `auth_version` revocation or
   audit paths. `LoginSecurityTest`, `CsrfTest` and `SessionRevocationTest`
   remain untouched and are covered by the existing suite.

## Test evidence

### Required backend gate

Command:

```bash
cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan test --compact \
  --filter='PasswordOnlyLoginTest|MfaDisabledApiAccessTest|MfaLoginChallengeTest|MfaTest|MfaPolicyTest|MfaEnrollmentInvariantTest'
```

Result: **29 passed (127 assertions)** in 10.35s.

Breakdown (by class):

| Suite | Tests | Result |
|---|---|---|
| `PasswordOnlyLoginTest` | 3 | PASS |
| `MfaDisabledApiAccessTest` | 4 | PASS |
| `MfaLoginChallengeTest` | 6 | PASS |
| `MfaTest` | 6 | PASS |
| `MfaPolicyTest` | 6 | PASS |
| `MfaEnrollmentInvariantTest` | 4 | PASS |

Note on the shared worktree: `v2-task2-impl` is concurrently developing the
single-company cutover. Its untracked migrations
(`2026_11_26_000300_*` and `2026_11_26_000320_*`) were present during this run
and delete `tenant_id`/`legal_entity_id` before the runtime cutover lands,
which makes every `RefreshDatabase` test fail with
`table users has no column named tenant_id`. The gate above was executed with
those two **untracked** migration files temporarily moved aside and restored
byte-for-byte afterwards (SHA-256 verified); they were never staged or
committed by this task. The same technique was used for
`InstallCommandTest` (**5 passed, 38 assertions**). The final workspace has
both files back and the owned-files tree clean.

Existing MFA suites already opted in with `config(['iam.mfa.enabled' => true])`;
no assertion was weakened or deleted.

### Frontend

```bash
cd frontend && pnpm exec vitest run tests/unit/LoginView.spec.ts
```

Result: **1 file, 4 tests passed**.

Broader affected run (not required, recorded for confidence):

```bash
cd frontend && pnpm exec vitest run tests/unit/platformPages.spec.ts \
  tests/unit/platformModulePages.spec.ts tests/unit/accountSecurity.spec.ts \
  tests/unit/mfaChallenge.spec.ts tests/unit/sessionPageGuard.spec.ts
```

Result: **5 files, 49 tests passed**.

```bash
cd frontend && pnpm typecheck
```

Result: **PASS**, exit 0 (`vue-tsc -b`).

### Static checks on owned files

```bash
cd backend && vendor/bin/pint --test <4 owned PHP files>
cd backend && vendor/bin/phpstan analyse <4 owned PHP files>
```

Result: Pint **PASS** (4 files); PHPStan **OK, no errors**.

```bash
cd frontend && pnpm exec eslint src/views/LoginView.vue \
  src/modules/platform/pages/AccountSecurityPage.vue tests/unit/LoginView.spec.ts --max-warnings=0
```

Result: **PASS**, no warnings.

## Concerns / follow-ups

- **Single-company collision (external to this task).** Until Task 2 commits
  the runtime model/route changes that accompany the drop-column migrations,
  the full backend suite cannot run in this shared worktree. The Task 10 gate
  is green in isolation; rerun it after Task 2 lands.
- **Account-security opt-in UI** still calls the existing
  `POST /v1/auth/mfa/totp/enroll` endpoint unconditionally when the user clicks
  the button. The backend keeps that endpoint available when the switch is off,
  so an administrator can pre-enrol a factor; the factor has no effect on login
  until `IAM_MFA_ENABLED=true`. The task brief's "hide the entry point when the
  switch is off" wording would require exposing the backend MFA switch on a
  public API (e.g. `/v1/meta`), which is outside the owned file list. The page
  therefore keeps the optional enrolment entry and labels it clearly as
  optional; the login page — the acceptance-critical surface — has no MFA
  controls at all.
- **Opt-in login challenge remains.** `LoginView.vue` still contains the TOTP
  step, but it is unreachable while MFA is disabled and only renders after the
  backend returns `MFA_REQUIRED`. Removing it would have broken the documented
  `IAM_MFA_ENABLED=true` path and the existing `mfa@demo.example` E2E journey.
- **Mock mode.** The development mock adapter still uses `mfa@demo.example` to
  exercise the opt-in challenge; this is synthetic demo behaviour, not the
  production default.
- Full repository-wide gates (`php artisan test`, capability audit, arch tests,
  OpenAPI lint) were **not** run because the shared worktree was mid-cutover;
  Task 12 owns the final full-suite evidence.

## Report path

`.superpowers/sdd/artifacts/v2/task-10-report.md`
