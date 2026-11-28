# T19-MCP-006 — user role assignment with org/case anchors

- Date: 2026-11-28
- Frontend image: `factoryhr/frontend:v24-t19f`
- Backend image: `factoryhr/php-app:v24-t19e`
- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Session: synthetic installer (SYSTEM_ADMIN)
- Target: synthetic user `t18.recruiter` (id 3, no employment link)

## Executed

1. Opened `/system/users`, selected `t18.recruiter` and 分配角色.
2. Selected the real `RECRUITER` role. The UI rendered two required inputs:
   `组织单元 ID` and `案例 ID`.
3. Submitted ORG_UNIT=4, CASE=2 and a synthetic reason.
4. Real POST `/v1/users/3/roles` returned **201**.
5. MySQL authority bindings at `auth_version=2` contain:
   - all recruiter ORG_UNIT capabilities at anchor 4;
   - `workflow.case.read` at CASE anchor 2;
   - `iam.session.manage` at SELF.
6. `t18.recruiter` password login returned **200** (MFA disabled).

## New finding — GAP-22

The recruiter role could log in, but `/recruitment/requests` then received:

- `403 /v1/recruitment/requisitions?page=1&page_size=50`;
- `403 /v1/engagement/notices?filter=unread`;
- `403 /v1/org-units/tree`.

The recruitment controller and those shared endpoints still hardcode
COMPANY anchor checks while the RECRUITER template grants ORG_UNIT. This is
recorded as GAP-22. The role assignment UI is fixed, but R08 business-page
access is not yet accepted.

## Evidence

- `user-recruiter-assigned.png` / `.yml`
- `recruiter-requisition-403.png` / `.yml`
- `recruiter-bindings.txt`
- `recruiter-requisitions-403.json`

No password, cookie, CSRF token or real personal data is stored.

## GAP-22 repair — FIXED in v24-t19g

- Recruitment collection reads now use `permits` + `constrain` and derive
  candidate visibility from visible requisitions.
- Requisition writes resolve the target org-unit anchor; recruiter template now
  includes `org.unit.read`.
- AppShell only requests unread notices when `engagement.notice.read` is granted.
- Real recruiter after repair:
  - requisitions: **200**;
  - candidates: **200**;
  - org tree: **200**;
  - notices: not requested by the shell (direct call remains an expected 403).
- R08 requests/candidates screenshots and network log are included.
