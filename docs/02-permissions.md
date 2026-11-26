# 02 — Permissions and authorization (IAM)

Status: 生产基线（授权引擎已落地并通过门禁）。来源：solution §7 与 §7.11、
plan §4.2/§5.2/§7、ADR-0016、ADR-0018；单公司边界见 ADR-0034。

## Model

Authorization is **Capability × Anchor × Field profile**:

- **Capability** — a code-owned action on a resource. The database
  `capabilities` table is a synced read model for UI/FK/audit only; there is no
  create/edit API. Adding a code requires code review, a negative test and
  audit metadata.
- **Anchor** — exactly one of seven data scopes: `SELF`, `MANAGED`, `ORG_UNIT`,
  `ORG_SUBTREE`, `CASE`, `PAYROLL_RUN`, `COMPANY`. There is no `CUSTOM`
  anchor and no `condition_json` or arbitrary SQL predicate. `COMPANY` replaces
  the former `LEGAL_ENTITY` anchor (ADR-0034: one company, no tenant/legal entity).
- **Field profile** — ordered sensitivity `P0_PUBLIC < P1_INTERNAL <
  P2_SENSITIVE < P3_RESTRICTED`. Row scope and field projection are resolved
  from the *same* binding, per row. Profiles never merge across bindings that
  do not cover the row.
- **Roles are capability packages.** A role expands into authority bindings;
  the role code is never an authorization check. There are fourteen code-owned templates and
  no person or department names may be used as role codes.
- **Deny by default.** A request with no matching binding, a stale
  `auth_version`, a disabled/locked user, an organization outside the binding, or an expired
  binding is denied before any row is returned.
- **Separation of duties is two different controls.** Cumulative risk (for
  example `iam.binding.manage` plus `iam.audit.read`) is allowed with an
  explicit `acknowledge_sod_risk`, reported as `data.warnings`, and audited as
  `security.sod_risk_accepted`. Object-level risk (preparing and approving the
  same payroll run) remains a hard invariant in the owning service.

### Hard-deny precedence

1. authentication and session validity,
2. live enabled user + current `auth_version`,
3. organization/company scope,
4. capability + effective binding dates,
5. anchor target (organisation, case membership, payroll run membership),
6. field-level visibility.

Object-level separation-of-duties checks run inside the owning service after
the object is loaded (for example `ApprovalService::assertSod()`); they are not
capability-set rules and are not bypassed by a wildcard grant.

## Page grants and action buttons

Page visibility is a separate authorization layer from action capability:

- `role_pages(role_code, page_code)` is the sole source of page visibility. There is no `tenant_id` or `legal_entity_id`; this is a single-company instance (ADR-0034).
- A page is visible only when the caller has at least one effective role granting that page; the page's `required_capability` is then checked for its read action.
- Action endpoints still require their own capability middleware. A page grant never authorizes a write/export/approval API.
- Every non-GET action button must be declared by `PageActionSpec` and rendered through `Can` or `ActionGate`. The button's `capability` must equal the endpoint's `x-capability`.
- `SUPER_ADMIN`/`SYSTEM_ADMIN` receive all 160 `role_pages` rows and all capabilities. `iam:verify-superadmin` fails if either side is incomplete.
- The role editor has four tabs: pages, buttons, data scope, field profile. Parent menu selection does not implicitly grant child pages.

## Seven anchors

| Anchor | Meaning | Resolution | Example capability |
|---|---|---|---|
| `SELF` | the caller's own employment(s) | `PersonEmploymentDirectory::employmentIdsForUser` | `payroll.payslip.read.self` |
| `MANAGED` | employments the caller manages on the business date | `PersonEmploymentDirectory::managedEmploymentIds` | `workflow.case.decide` |
| `ORG_UNIT` | one organization unit | unit id as of the business date | `people.employee.read` |
| `ORG_SUBTREE` | an organization subtree | closure/as-of descendant ids | `org.tree.read` |
| `CASE` | one workflow case the user belongs to | `CaseMembershipDirectory::isMember` | `workflow.case.decide` |
| `PAYROLL_RUN` | one payroll run the user participates in | `PayrollRunMembershipDirectory::isMember` | `payroll.run.approve` |
| `COMPANY` | the whole single-company instance | no id required; company profile | `iam.user.manage` |

IAM depends only on narrow ports in `App\Shared\Authorization\Contracts`:
`PersonEmploymentDirectory`, `OrganizationScopeDirectory`,
`CaseMembershipDirectory`, `PayrollRunMembershipDirectory`. It never imports
People, Organization, Workflow or Payroll models/services. Test fakes live in
`tests/Fakes`.

## Capability catalog

All codes are owned by `App\Modules\IAM\Authorization\CapabilityCatalog`.

| Capability | Resource | Anchors | Max field profile | Risk |
|---|---|---|---|---|
| `iam.user.read` | user | SELF, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | high |
| `iam.user.manage` | user | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | critical |
| `iam.role.manage` | role | COMPANY | P1_INTERNAL | critical |
| `iam.binding.manage` | authority_binding | COMPANY | P1_INTERNAL | critical |
| `iam.session.manage` | session | SELF | P0_PUBLIC | low (intrinsic, non-delegable) |
| `iam.audit.read` | auth_event | COMPANY | P2_SENSITIVE | high |
| `org.unit.read` | org_unit | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `org.unit.manage` | org_unit | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | high |
| `org.tree.read` | org_tree | ORG_UNIT, ORG_SUBTREE, COMPANY | P0_PUBLIC | low |
| `people.employee.read` | employee | all seven | P2_SENSITIVE | high |
| `people.employee.manage` | employee | ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | high |
| `people.employee.archive` | employee | ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | high |
| `people.employee.restore` | employee | ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | medium |
| `people.assignment.manage` | assignment | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | high |
| `people.contract.read` | contract | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | high |
| `people.contract.manage` | contract | ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | high |
| `people.contract.delete` | contract | COMPANY | P2_SENSITIVE | high |
| `people.contract.restore` | contract | COMPANY | P2_SENSITIVE | medium |
| `workflow.case.read` | case | SELF, MANAGED, CASE, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `workflow.case.create` | case | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `workflow.case.decide` | case | CASE, MANAGED | P1_INTERNAL | high |
| `workflow.case.admin` | case | CASE, COMPANY | P2_SENSITIVE | critical |
| `time.attendance.read` | attendance_day | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `time.attendance.manage` | attendance_day | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | high |
| `time.punch.import` | punch_record | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | high |
| `time.attendance.correct.request` | attendance_correction | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `time.calendar.read` | company_calendar | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | low |
| `time.shift.read` | shift_template | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | low |
| `time.leave.read` | leave_account | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `time.leave.request` | leave_request | SELF | P0_PUBLIC | low |
| `time.leave.admin` | leave_account | ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | high |
| `time.leave.request.cancel` | leave_request | SELF | P0_PUBLIC | low |
| `time.leave.ledger.read` | leave_ledger | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `payroll.run.read` | payroll_run | PAYROLL_RUN, COMPANY | P2_SENSITIVE | high |
| `payroll.run.prepare` | payroll_run | PAYROLL_RUN, COMPANY | P2_SENSITIVE | critical |
| `payroll.run.review` | payroll_run | PAYROLL_RUN, COMPANY | P2_SENSITIVE | critical |
| `payroll.run.approve` | payroll_run | PAYROLL_RUN, COMPANY | P2_SENSITIVE | critical |
| `payroll.run.publish` | payroll_run | PAYROLL_RUN, COMPANY | P2_SENSITIVE | critical |
| `payroll.payslip.read` | payslip | SELF, PAYROLL_RUN, COMPANY | P3_RESTRICTED | high |
| `payroll.payslip.read.self` | payslip | SELF | P3_RESTRICTED | medium |
| `payroll.export` | payroll_run | PAYROLL_RUN, COMPANY | P3_RESTRICTED | critical |
| `talent.recruitment.manage` | recruitment | ORG_UNIT, ORG_SUBTREE, CASE, COMPANY | P2_SENSITIVE | high |
| `talent.performance.manage` | performance | MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | high |
| `talent.training.manage` | training | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `engagement.notice.read` | notice | SELF, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | low |
| `engagement.notice.manage` | notice | ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `reporting.dashboard.read` | dashboard | SELF, MANAGED, ORG_UNIT, ORG_SUBTREE, COMPANY | P1_INTERNAL | medium |
| `reporting.export` | report | ORG_UNIT, ORG_SUBTREE, COMPANY | P2_SENSITIVE | high |

`iam.session.manage` is intrinsic self-service: middleware grants it only when
the request has a live server-side session. It is not issued by any role and
cannot be delegated.

## Fourteen role templates

| Template | Purpose | Representative anchors |
|---|---|---|
| `SUPER_ADMIN` | emergency administration; time-boxed and reviewed | COMPANY |
| `SYSTEM_ADMIN` | 全能力通配绑定（`*`），含薪酬与审计；受 366 天上限、季度复核与 SoD 风险确认约束 | COMPANY |
| `HR_ADMIN` | people, contracts, organization and HR case execution | COMPANY |
| `HR_SPECIALIST` | day-to-day employee, assignment, contract work | ORG_UNIT |
| `RECRUITER` | recruitment pipeline and candidate cases | ORG_UNIT, CASE |
| `PAYROLL_SPECIALIST` | prepare payroll only; approval/publication are separate | PAYROLL_RUN, COMPANY |
| `PAYROLL_APPROVER` | review/approve payroll within assigned runs | PAYROLL_RUN, COMPANY |
| `ATTENDANCE_SPECIALIST` | attendance correction and leave administration | ORG_UNIT |
| `LINE_MANAGER` | team approvals and team visibility | MANAGED |
| `EMPLOYEE` | own profile, leave, attendance, payslips, notices | SELF |
| `AUDITOR` | read-only audit evidence and organization context | COMPANY |
| `TRAINING_ADMIN` | training plans, sessions and enrollment administration | ORG_UNIT, COMPANY |
| `BENEFITS_ADMIN` | benefit plans and enrollment administration | ORG_UNIT, COMPANY |
| `REPORT_VIEWER` | read-only reports within granted organization scope | ORG_UNIT, ORG_SUBTREE, COMPANY |

Role assignments must supply every required anchor id
(`binding_anchors.<ANCHOR>`); `COMPANY` requires no anchor id and no request header. Codes must match `^[A-Z][A-Z0-9_]{2,63}$`.

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
- **MFA is disabled by default** (`IAM_MFA_ENABLED=false`, ADR-0018):
  username + password is sufficient, including for `SYSTEM_ADMIN`. Login rate
  limiting, lockout, `auth_version` invalidation and the capability gate still
  apply. Set `IAM_MFA_ENABLED=true` to restore the controls below.
- When enabled: privileged users and P2/P3 holders must enrol MFA
  or receive `403 MFA_REQUIRED`. High-risk mutations (user/role/binding
  changes, break-glass, payroll export) require a fresh `mfa.step-up` within
  15 minutes (`403 MFA_STEP_UP_REQUIRED`).
- Break-glass requires a requester, a distinct active approver, a reason of at
  least 10 characters and MFA enrollment. Grants last 30 minutes, are revocable
  by either party, emit `security.break_glass.activated` audit + outbox events
  and expire automatically. `SUPER_ADMIN` and `SYSTEM_ADMIN` hold the
  wildcard `*` under `COMPANY`/`P3_RESTRICTED`, time-boxed to 30/366 days
  by `iam:bind-role`; no unreviewed permanent super administrator exists.

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
| MFA missing / stale (only when `IAM_MFA_ENABLED=true`) | `403 MFA_REQUIRED` / `403 MFA_STEP_UP_REQUIRED` |
| CSRF missing/invalid | `419 CSRF_FAILED` |
| stale `auth_version` / revoked session | `401 UNAUTHENTICATED` |
| unacknowledged cumulative SOD risk | `422 VALIDATION_FAILED` / `SOD_RISK_NOT_ACKNOWLEDGED` |
| object-level SOD conflict | `409 CONFLICT` / `SOD_CONFLICT` |
| rate limit | `429 RATE_LIMITED` |

## Commands

```bash
php artisan iam:sync-capabilities   # code catalog + 10 role templates -> read models
php artisan iam:seed-demo           # requires DEMO_SEED_PASSWORD >= 12 chars
php artisan iam:reap-sessions
php artisan iam:access-review --quarter=2026-Q4
php artisan hrms:install --legal-entity-code=ACME-CN ...  # production baseline
php artisan iam:verify-mfa-enrollment
```

`iam:access-review` prints one JSON object. Cumulative and object-level risk
rows are under `sod_risks`, each shaped as
`{principal_id, capabilities: [code, code], kind}`; the key is not
`sod_conflicts`.

## Separation of duties

- **Cumulative** risk (for example `iam.binding.manage` plus
  `iam.audit.read`) is a job-design signal. Granting it requires
  `acknowledge_sod_risk: true`, returns `data.warnings`, writes
  `security.sod_risk_accepted`, and appears in the quarterly access review.
- **Object-level** risk (preparing and approving the same payroll run) is a
  hard invariant enforced by `ApprovalService`, `BreakGlassService` and
  `ReopenService` (409 `SOD_CONFLICT`). A wildcard grant never bypasses it.
