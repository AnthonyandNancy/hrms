# T19-MCP-012 — payroll lifecycle, SoD and deterministic approval template

- Date: 2026-11-28
- Backend image: `factoryhr/php-app:v24-t19k`
- Frontend image: `factoryhr/frontend:v24-t19i`
- Real stack: `factoryhr-t19`
- Synthetic statutory parameters: TAX / SOCIAL_INSURANCE / HOUSING_FUND,
  effective 2026-01-01, explicitly marked demo and not legal advice.
- Run: #1, 2026-01, four synthetic demo employees.

## Lifecycle

1. Created approved statutory parameter versions (TAX 0% bracket, SI/HF 0%).
2. R04 `t18.payroll` locked inputs: INPUT_LOCKED.
3. R04 calculated: 4 calculated, 0 failed/blocked,
   gross=net=5,000,000 cents.
4. R05 `t18.approver` reviewed: REVIEWED.
5. Same user tried approve: **409 SOD_CONFLICT** (`approver` conflicts with
   `reviewer`), proving four-eyes enforcement.
6. Second approver `t18.approver2` approved: **APPROVED**,
   approved_by=13.
7. Same approver tried publish: **409 SOD_CONFLICT** (`publisher` conflicts
   with `approver`).
8. Third user `t18.publisher` (PUBLISHER membership) published: **PUBLISHED**,
   published_by=14.
9. DB after publish:
   - 4 payslips;
   - 4 YTD ledger entries;
   - 2 notification deliveries.

## Root-cause repair

The install seed had published `payroll.approval` v1 with
`MANAGER_OF_SUBJECT` + `workflow.noop`. The approval adapter reused it and
failed with “no eligible assignee”.

- Seed now creates v2 with CASE_MEMBER/APPROVER and
  `workflow.payroll.publish`.
- Approval service detects a legacy non-deterministic template and creates the
  next deterministic published version without mutating history.
- Legacy regression added; full gate passed (22 gates, 240.5s).

## Evidence

`published-run.png/.yml`, `published-run-network.txt`, parameter responses,
lock/calculate/review/approve/publish responses, `publish-db.txt`,
`legacy-template.txt`, `template-versions.txt`.

R04/R05 API/DB evidence is real; the apply lifecycle actions were executed by
authenticated API commands as setup for the final MCP page capture, not through
button clicks. UI button parity remains part of the full action matrix.
