# 02 — Permissions and authorization (IAM)

Status: Task 2 reference implementation. Binding sources: solution §7 and
§7.11, plan §4.2/§5.2/§7, `.superpowers/sdd/artifacts/interface-freeze.md`.

## Model

Authorization is **Capability × Anchor × Field profile**:

- **Capability** — a code-owned action on a resource. The database
  `capabilities` table is a synced read model for UI/FK/audit only; there is no
  create/edit API. Adding a code requires code review, a negative test and
  audit metadata.
- **Anchor** — exactly one of seven data scopes: `SELF`, `MANAGED`, `ORG_UNIT`,
  `ORG_SUBTREE`, `CASE`, `PAYROLL_RUN`, `LEGAL_ENTITY`. There is no `CUSTOM`
  anchor and no `condition_json` or arbitrary SQL predicate.
- **Field profile** — ordered sensitivity `P0_PUBLIC < P1_INTERNAL <
  P2_SENSITIVE < P3_RESTRICTED`. Row scope and field projection are resolved
  from the *same* binding, per row. Profiles never merge across bindings that
  do not cover the row.
- **Roles are capability packages.** A role expands into authority bindings;
  the role code is never an authorization check. There are ten templates and
  no person or department names may be used as role codes.
- **Deny by default.** A request with no matching binding, a stale
  `auth_version`, a disabled/locked user, a tenant mismatch, an expired
  binding, or an SOD conflict is denied before any row is returned.

### Hard-deny precedence

1. authentication and session validity,
2. live enabled user + current `auth_version`,
3. tenant match,
4. capability + effective binding dates,
5. separation-of-duties conflict,
6. anchor target (organisation, case membership, payroll run membership),
7. field-level visibility.

## Seven anchors

| Anchor | Meaning | Resolution | Example capability |
|---|---|---|---|
| `SELF` | the caller's own employment(s) | `PersonEmploymentDirectory::employmentIdsForUser` | `payroll.payslip.read.self` |
| `MANAGED` | employments the caller manages on the business date | `PersonEmploymentDirectory::managedEmploymentIds` | `workflow.case.decide` |
| `ORG_UNIT` | one organization unit | unit id as of the business date | `people.employee.read` |
| `ORG_SUBTREE` | an organization subtree | closure/as-of descendant ids | `org.tree.read` |
| `CASE` | one workflow case the user belongs to | `CaseMembershipDirectory::isMember` | `workflow.case.decide` |
| `PAYROLL_RUN` | one payroll run the user participates in | `PayrollRunMembershipDirectory::isMember` | `payroll.run.approve` |
| `LEGAL_ENTITY` | one legal entity | `OrganizationScopeDirectory` | `iam.user.manage` |

IAM depends only on narrow ports in `App\Shared\Authorization\Contracts`:
`PersonEmploymentDirectory`, `OrganizationScopeDirectory`,
`CaseMembershipDirectory`, `PayrollRunMembershipDirectory`. It never imports
People, Organization, Workflow or Payroll models/services. Test fakes live in
`tests/Fakes`.

## Capability catalog

All codes are owned by `App\Modules\IAM\Authorization\CapabilityCatalog`.

| Capability | Resource | Anchors | Max field profile | Risk |
|---|---|---|---|---|
| `iam.user.read` | user | SELF, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | high |
| `iam.user.manage` | user | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | critical |
| `iam.role.manage` | role | LEGAL_ENTITY | P1_INTERNAL | critical |
| `iam.binding.manage` | authority_binding | LEGAL_ENTITY | P1_INTERNAL | critical |
| `iam.session.manage` | session | SELF | P0_PUBLIC | low (intrinsic, non-delegable) |
| `iam.audit.read` | auth_event | LEGAL_ENTITY | P2_SENSITIVE | high |
| `org.unit.read` | org_unit | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `org.unit.manage` | org_unit | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | high |
| `org.tree.read` | org_tree | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P0_PUBLIC | low |
| `people.employee.read` | employee | all seven | P2_SENSITIVE | high |
| `people.employee.manage` | employee | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P2_SENSITIVE | high |
| `people.assignment.manage` | assignment | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | high |
| `people.contract.read` | contract | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P2_SENSITIVE | high |
| `people.contract.manage` | contract | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P2_SENSITIVE | high |
| `workflow.case.read` | case | SELF, MANAGED, CASE, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `workflow.case.create` | case | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `workflow.case.decide` | case | CASE, MANAGED | P1_INTERNAL | high |
| `workflow.case.admin` | case | CASE, LEGAL_ENTITY | P2_SENSITIVE | critical |
| `time.attendance.read` | attendance_day | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `time.attendance.manage` | attendance_day | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | high |
| `time.punch.import` | punch_record | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | high |
| `time.attendance.correct.request` | attendance_correction | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `time.calendar.read` | legal_entity_calendar | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | low |
| `time.shift.read` | shift_template | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | low |
| `time.leave.read` | leave_account | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `time.leave.request` | leave_request | SELF | P0_PUBLIC | low |
| `time.leave.admin` | leave_account | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P2_SENSITIVE | high |
| `time.leave.request.cancel` | leave_request | SELF | P0_PUBLIC | low |
| `time.leave.ledger.read` | leave_ledger | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `payroll.run.read` | payroll_run | PAYROLL_RUN, LEGAL_ENTITY | P2_SENSITIVE | high |
| `payroll.run.prepare` | payroll_run | PAYROLL_RUN, LEGAL_ENTITY | P2_SENSITIVE | critical |
| `payroll.run.review` | payroll_run | PAYROLL_RUN, LEGAL_ENTITY | P2_SENSITIVE | critical |
| `payroll.run.approve` | payroll_run | PAYROLL_RUN, LEGAL_ENTITY | P2_SENSITIVE | critical |
| `payroll.run.publish` | payroll_run | PAYROLL_RUN, LEGAL_ENTITY | P2_SENSITIVE | critical |
| `payroll.payslip.read` | payslip | SELF, PAYROLL_RUN, LEGAL_ENTITY | P3_RESTRICTED | high |
| `payroll.payslip.read.self` | payslip | SELF | P3_RESTRICTED | medium |
| `payroll.export` | payroll_run | PAYROLL_RUN, LEGAL_ENTITY | P3_RESTRICTED | critical |
| `talent.recruitment.manage` | recruitment | ORG_UNIT, ORG_SUBTREE, CASE, LEGAL_ENTITY | P2_SENSITIVE | high |
| `talent.performance.manage` | performance | MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P2_SENSITIVE | high |
| `talent.training.manage` | training | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `engagement.notice.read` | notice | SELF, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | low |
| `engagement.notice.manage` | notice | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `reporting.dashboard.read` | dashboard | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P1_INTERNAL | medium |
| `reporting.export` | report | ORG_UNIT, ORG_SUBTREE, LEGAL_ENTITY | P2_SENSITIVE | high |

`iam.session.manage` is intrinsic self-service: middleware grants it only when
the request has a live server-side session. It is not issued by any role and
cannot be delegated.

## Ten role templates

| Template | Purpose | Representative anchors |
|---|---|---|
| `SUPER_ADMIN` | emergency administration; time-boxed and reviewed | LEGAL_ENTITY |
| `SYSTEM_ADMIN` | operate accounts, roles, organization metadata; no payroll/audit | LEGAL_ENTITY |
| `HR_ADMIN` | people, contracts, organization and HR case execution | LEGAL_ENTITY |
| `HR_SPECIALIST` | day-to-day employee, assignment, contract work | ORG_UNIT |
| `RECRUITER` | recruitment pipeline and candidate cases | ORG_UNIT, CASE |
| `PAYROLL_SPECIALIST` | prepare payroll only; approval/publication are separate | PAYROLL_RUN, LEGAL_ENTITY |
| `ATTENDANCE_SPECIALIST` | attendance correction and leave administration | ORG_UNIT |
| `LINE_MANAGER` | team approvals and team visibility | MANAGED |
| `EMPLOYEE` | own profile, leave, attendance, payslips, notices | SELF |
| `AUDITOR` | read-only audit evidence and organization context | LEGAL_ENTITY |

Role assignments must supply every required anchor id
(`binding_anchors.<ANCHOR>`); `LEGAL_ENTITY` may be taken from the request's
legal-entity header. Codes must match `^[A-Z][A-Z0-9_]{2,63}$`.

## Field profiles

| Profile | Behaviour |
|---|---|
| `P0_PUBLIC` | returned as-is; filterable; exportable |
| `P1_INTERNAL` | masked in output; filterable; export only with an export capability |
| `P2_SENSITIVE` | masked; **not filterable or sortable** (±422 `FIELD_NOT_FILTERABLE`); export only with an export capability |
| `P3_RESTRICTED` | never in API output, filters, sorts, logs or exports (`FIELD_NOT_VISIBLE`) |

`FieldSet::mask()` removes hidden keys first, so a P3 key can never be
serialised. Filtering/sorting uses only the declared filterable set; there is
no post-filtering after pagination. Returning P2/P3 data emits a
`sensitive_field_access` audit event containing field names only.

## Sessions, CSRF and revocation

- Server-side sessions; only SHA-256 hashes of the 32-byte bearer token and
  CSRF token are stored. HttpOnly, Secure (config-driven), SameSite=Lax cookie.
- Standard: 30-minute idle, 12-hour absolute. Privileged: 15-minute idle,
  8-hour absolute.
- `X-CSRF-Token` is required for every mutating request; missing/invalid is
  `419 CSRF_FAILED`.
- Every request re-checks `auth_version`. Role change, binding change,
  password change and offboarding increment it; the next request gets `401`
  and the session is revoked.

## MFA and break-glass

- TOTP is dependency-free RFC 6238 (HMAC-SHA1, 30 s step, ±1 window) and is
  covered by the official Appendix B vectors. Secrets are encrypted at rest.
- WebAuthn registration/assertion challenge verification checks type,
  challenge, origin and `rpIdHash`; signature success is not faked.
- SMS/email are rejected as second factors.
- Privileged users and payroll prepare/approve/publish holders must enrol MFA
  or receive `403 MFA_REQUIRED`. High-risk mutations (user/role/binding
  changes, break-glass, payroll export) require a fresh `mfa.step-up` within
  15 minutes (`403 MFA_STEP_UP_REQUIRED`).
- Break-glass requires a requester, a distinct active approver, a reason of at
  least 10 characters and MFA enrollment. Grants last 30 minutes, are revocable
  by either party, emit `security.break_glass.activated` audit + outbox events
  and expire automatically. No permanent super administrator exists.

## Negative test matrix

`backend/tests/Authorization/endpoint_matrix.php` is the machine-readable
matrix. Every protected endpoint has at least: unauthenticated, no capability,
wrong anchor, field denial. High-risk endpoints additionally cover cross-legal-
entity, old auth version, IDOR, CSRF and SOD.

| Class | Expected result |
|---|---|
| unauthenticated | `401 UNAUTHENTICATED` |
| no capability | `403 FORBIDDEN` / `CAPABILITY_DENIED` |
| wrong anchor / cross entity | `403 FORBIDDEN` or `404` when the target must not be disclosed |
| P2/P3 filter or sort | `422 VALIDATION_FAILED` / `FIELD_NOT_FILTERABLE` |
| P3 serialisation attempt | field absent; `FIELD_NOT_VISIBLE` for explicit request |
| MFA missing / stale | `403 MFA_REQUIRED` / `403 MFA_STEP_UP_REQUIRED` |
| CSRF missing/invalid | `419 CSRF_FAILED` |
| stale `auth_version` / revoked session | `401 UNAUTHENTICATED` |
| SOD conflict | `409 CONFLICT` |
| rate limit | `429 RATE_LIMITED` |

## Commands

```bash
php artisan iam:sync-capabilities   # code catalog + 10 role templates -> read models
php artisan iam:seed-demo           # requires DEMO_SEED_PASSWORD >= 12 chars
php artisan iam:reap-sessions
php artisan iam:access-review --quarter=2026-Q4
```
