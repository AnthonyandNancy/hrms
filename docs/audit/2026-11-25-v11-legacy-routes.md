# V1.1 legacy 路由台账

> 本文件由 `php scripts/dump_routes.php` + `python .superpowers/sdd/v11-gap/gen_ledger.py` 生成，
> **不是**手工清单。`kernel` 由“控制器是否引用 CommandRunner/ProjectionResponder”判定，
> 不依赖人工声明，因此新端点一旦使用内核就会自动升级。

- v1 操作总数：**224**
- 有能力的操作：**218**
- 公共白名单操作：**6**
- kernel 操作：**37**

## 1. 台账

| # | 方法 | 路径 | 能力 | 锚点 | 类型 | 迁移 Task |
|---:|---|---|---|---|---|---|
| 1 | `GET` | `/v1/access-review` | `iam.audit.read` | `LEGAL_ENTITY` | legacy | D1 |
| 2 | `POST` | `/v1/auth/break-glass` | `iam.session.manage` | `SELF` | legacy | E3 |
| 3 | `POST` | `/v1/auth/break-glass/{id}/revoke` | `iam.session.manage` | `SELF` | legacy | E3 |
| 4 | `GET` | `/v1/auth/events` | `iam.audit.read` | `LEGAL_ENTITY` | legacy | E3 |
| 5 | `POST` | `/v1/auth/login` | `—` | `—` | kernel | — |
| 6 | `POST` | `/v1/auth/logout` | `iam.session.manage` | `SELF` | legacy | E3 |
| 7 | `POST` | `/v1/auth/logout-all` | `iam.session.manage` | `SELF` | legacy | E3 |
| 8 | `POST` | `/v1/auth/mfa/challenge` | `—` | `—` | kernel | — |
| 9 | `POST` | `/v1/auth/mfa/step-up` | `iam.session.manage` | `SELF` | legacy | E3 |
| 10 | `POST` | `/v1/auth/mfa/totp/confirm` | `iam.session.manage` | `SELF` | legacy | E3 |
| 11 | `POST` | `/v1/auth/mfa/totp/enroll` | `iam.session.manage` | `SELF` | legacy | E3 |
| 12 | `POST` | `/v1/auth/password` | `iam.session.manage` | `SELF` | legacy | E3 |
| 13 | `POST` | `/v1/auth/webauthn/assert/options` | `iam.session.manage` | `SELF` | legacy | E3 |
| 14 | `POST` | `/v1/auth/webauthn/register/options` | `iam.session.manage` | `SELF` | legacy | E3 |
| 15 | `DELETE` | `/v1/bindings/{id}` | `iam.binding.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 16 | `GET` | `/v1/capabilities` | `iam.role.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 17 | `GET` | `/v1/cases` | `workflow.case.read` | `—` | legacy | E3 |
| 18 | `POST` | `/v1/cases` | `workflow.case.create` | `—` | legacy | E3 |
| 19 | `POST` | `/v1/cases/{caseId}/decisions` | `workflow.case.decide` | `—` | legacy | E3 |
| 20 | `GET` | `/v1/cases/{id}` | `workflow.case.read` | `—` | legacy | E3 |
| 21 | `POST` | `/v1/cases/{id}/submit` | `workflow.case.create` | `—` | legacy | E3 |
| 22 | `GET` | `/v1/cases/{id}/timeline` | `workflow.case.read` | `—` | legacy | E3 |
| 23 | `POST` | `/v1/cases/{id}/withdraw` | `workflow.case.create` | `—` | legacy | E3 |
| 24 | `GET` | `/v1/contract-templates` | `people.contract.read` | `—` | kernel | — |
| 25 | `POST` | `/v1/contract-templates` | `people.contract.template.manage` | `—` | kernel | — |
| 26 | `POST` | `/v1/contract-templates/{id}/publish` | `people.contract.template.manage` | `—` | kernel | — |
| 27 | `POST` | `/v1/delegations` | `workflow.case.decide` | `—` | legacy | E3 |
| 28 | `DELETE` | `/v1/delegations/{id}` | `workflow.case.decide` | `—` | legacy | E3 |
| 29 | `GET` | `/v1/departments` | `org.unit.read` | `—` | legacy | C1 |
| 30 | `GET` | `/v1/departments/staffing` | `org.unit.read` | `—` | legacy | C1 |
| 31 | `GET` | `/v1/departments/{id}/members` | `org.unit.read` | `—` | legacy | C1 |
| 32 | `POST` | `/v1/departments/{id}/members` | `org.unit.manage` | `—` | legacy | C1 |
| 33 | `DELETE` | `/v1/documents/{document_id}` | `people.employee.manage` | `—` | kernel | — |
| 34 | `GET` | `/v1/documents/{document_id}/content` | `people.document.download` | `—` | kernel | — |
| 35 | `GET` | `/v1/documents/{document_id}/download` | `people.document.download` | `—` | legacy | C4 |
| 36 | `GET` | `/v1/documents/{document_id}/signed-download` | `people.document.download` | `—` | kernel | — |
| 37 | `POST` | `/v1/effects/{id}/retry` | `workflow.case.admin` | `—` | legacy | E3 |
| 38 | `GET` | `/v1/employees` | `people.employee.read` | `LEGAL_ENTITY` | legacy | E3 |
| 39 | `GET` | `/v1/employees/{employmentId}/certificates` | `people.employee.read` | `—` | legacy | E3 |
| 40 | `POST` | `/v1/employees/{employmentId}/certificates` | `people.employee.manage` | `—` | legacy | E3 |
| 41 | `PATCH` | `/v1/employees/{employmentId}/certificates/{id}` | `people.employee.manage` | `—` | legacy | E3 |
| 42 | `GET` | `/v1/employees/{employmentId}/change_requests` | `people.employee.read` | `—` | legacy | E3 |
| 43 | `POST` | `/v1/employees/{employmentId}/change_requests` | `people.employee.manage` | `—` | legacy | E3 |
| 44 | `PATCH` | `/v1/employees/{employmentId}/change_requests/{id}` | `people.employee.manage` | `—` | legacy | E3 |
| 45 | `POST` | `/v1/employees/{employmentId}/change_requests/{id}/submit` | `people.employee.manage` | `—` | legacy | E3 |
| 46 | `GET` | `/v1/employees/{employmentId}/contacts` | `people.employee.read` | `—` | legacy | E3 |
| 47 | `POST` | `/v1/employees/{employmentId}/contacts` | `people.employee.manage` | `—` | legacy | E3 |
| 48 | `PATCH` | `/v1/employees/{employmentId}/contacts/{id}` | `people.employee.manage` | `—` | legacy | E3 |
| 49 | `GET` | `/v1/employees/{employmentId}/education` | `people.employee.read` | `—` | legacy | E3 |
| 50 | `POST` | `/v1/employees/{employmentId}/education` | `people.employee.manage` | `—` | legacy | E3 |
| 51 | `PATCH` | `/v1/employees/{employmentId}/education/{id}` | `people.employee.manage` | `—` | legacy | E3 |
| 52 | `GET` | `/v1/employees/{employmentId}/experiences` | `people.employee.read` | `—` | legacy | E3 |
| 53 | `POST` | `/v1/employees/{employmentId}/experiences` | `people.employee.manage` | `—` | legacy | E3 |
| 54 | `PATCH` | `/v1/employees/{employmentId}/experiences/{id}` | `people.employee.manage` | `—` | legacy | E3 |
| 55 | `GET` | `/v1/employees/{employmentId}/imports` | `people.employee.read` | `—` | legacy | E3 |
| 56 | `POST` | `/v1/employees/{employmentId}/imports` | `people.employee.manage` | `—` | legacy | E3 |
| 57 | `PATCH` | `/v1/employees/{employmentId}/imports/{id}` | `people.employee.manage` | `—` | legacy | E3 |
| 58 | `GET` | `/v1/employees/{employment_id}` | `people.employee.read` | `LEGAL_ENTITY` | legacy | E3 |
| 59 | `GET` | `/v1/employees/{employment_id}/onboarding-checklist` | `lifecycle.case.read` | `—` | legacy | E3 |
| 60 | `GET` | `/v1/employment-contracts/{id}/amendments` | `people.contract.read` | `—` | kernel | — |
| 61 | `POST` | `/v1/employment-contracts/{id}/amendments` | `people.contract.amend` | `—` | kernel | — |
| 62 | `POST` | `/v1/employment-contracts/{id}/terminate` | `people.contract.terminate` | `—` | kernel | — |
| 63 | `POST` | `/v1/employments` | `people.employee.manage` | `—` | legacy | E3 |
| 64 | `GET` | `/v1/employments/contracts` | `people.contract.read` | `—` | kernel | — |
| 65 | `GET` | `/v1/employments/contracts/expiring` | `people.contract.read` | `—` | legacy | B1 |
| 66 | `GET` | `/v1/employments/{employmentId}/contracts` | `people.contract.read` | `—` | legacy | E3 |
| 67 | `POST` | `/v1/employments/{employmentId}/contracts` | `people.contract.manage` | `—` | legacy | E3 |
| 68 | `GET` | `/v1/employments/{employmentId}/contracts/{id}` | `people.contract.read` | `—` | legacy | E3 |
| 69 | `POST` | `/v1/employments/{employment_id}/assignments` | `people.assignment.manage` | `—` | legacy | E3 |
| 70 | `GET` | `/v1/employments/{employment_id}/document-files` | `people.document.read` | `—` | kernel | — |
| 71 | `POST` | `/v1/employments/{employment_id}/document-files` | `people.document.read` | `—` | kernel | — |
| 72 | `POST` | `/v1/employments/{employment_id}/documents` | `people.contract.manage` | `—` | legacy | E3 |
| 73 | `POST` | `/v1/employments/{employment_id}/terms` | `people.contract.manage` | `—` | legacy | E3 |
| 74 | `GET` | `/v1/employments/{employment_id}/timeline` | `people.employee.read` | `—` | legacy | E3 |
| 75 | `POST` | `/v1/employments/{employment_id}/transfer` | `people.employee.manage` | `—` | legacy | E3 |
| 76 | `GET` | `/v1/engagement/notices` | `engagement.notice.read` | `—` | legacy | D1 |
| 77 | `POST` | `/v1/engagement/notices` | `engagement.notice.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 78 | `GET` | `/v1/engagement/notices/{notice}` | `engagement.notice.read` | `—` | legacy | D1 |
| 79 | `POST` | `/v1/engagement/notices/{notice}/acknowledge` | `engagement.notice.read` | `—` | legacy | D1 |
| 80 | `POST` | `/v1/engagement/notices/{notice}/retract` | `engagement.notice.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 81 | `GET` | `/v1/engagement/notifications` | `engagement.notification.read` | `—` | legacy | E3 |
| 82 | `POST` | `/v1/engagement/notifications/{notification}/read` | `engagement.notification.read` | `—` | legacy | E3 |
| 83 | `GET` | `/v1/health` | `—` | `—` | kernel | — |
| 84 | `GET` | `/v1/health/live` | `—` | `—` | kernel | — |
| 85 | `GET` | `/v1/health/ready` | `—` | `—` | kernel | — |
| 86 | `GET` | `/v1/leave/balances/{employment_id}` | `time.leave.read` | `—` | legacy | E3 |
| 87 | `GET` | `/v1/leave/ledger/{employment_id}` | `time.leave.ledger.read` | `—` | legacy | E3 |
| 88 | `POST` | `/v1/leave/requests` | `time.leave.request` | `—` | legacy | E3 |
| 89 | `POST` | `/v1/leave/requests/{caseId}/cancel` | `time.leave.request.cancel` | `—` | legacy | E3 |
| 90 | `GET` | `/v1/lifecycle/cases` | `lifecycle.case.read` | `—` | legacy | C1 |
| 91 | `GET` | `/v1/lifecycle/cases/{caseId}` | `lifecycle.case.read` | `—` | legacy | C1 |
| 92 | `POST` | `/v1/lifecycle/contract-renewals` | `lifecycle.contract.manage` | `—` | legacy | C1 |
| 93 | `POST` | `/v1/lifecycle/offboardings` | `lifecycle.offboard.manage` | `—` | legacy | C1 |
| 94 | `POST` | `/v1/lifecycle/onboarding` | `lifecycle.onboarding.manage` | `LEGAL_ENTITY` | legacy | C1 |
| 95 | `POST` | `/v1/lifecycle/probation-confirmations` | `lifecycle.probation.manage` | `—` | legacy | C1 |
| 96 | `POST` | `/v1/lifecycle/rehires` | `lifecycle.rehire.manage` | `LEGAL_ENTITY` | legacy | C1 |
| 97 | `POST` | `/v1/lifecycle/salary-changes` | `lifecycle.terms.manage` | `—` | legacy | C1 |
| 98 | `POST` | `/v1/lifecycle/transfers` | `lifecycle.transfer.manage` | `LEGAL_ENTITY` | legacy | C1 |
| 99 | `GET` | `/v1/me` | `iam.session.manage` | `SELF` | legacy | E3 |
| 100 | `GET` | `/v1/me/capabilities` | `iam.session.manage` | `SELF` | legacy | E3 |
| 101 | `GET` | `/v1/me/permissions` | `iam.session.manage` | `SELF` | legacy | E3 |
| 102 | `GET` | `/v1/me/workspace` | `iam.session.manage` | `SELF` | legacy | E3 |
| 103 | `GET` | `/v1/meta` | `—` | `—` | kernel | — |
| 104 | `PATCH` | `/v1/onboarding-tasks/{id}` | `lifecycle.checklist.manage` | `—` | legacy | E3 |
| 105 | `POST` | `/v1/org-units` | `org.unit.manage` | `—` | legacy | C1 |
| 106 | `GET` | `/v1/org-units/tree` | `org.unit.read` | `—` | legacy | C1 |
| 107 | `GET` | `/v1/org-units/{id}/impact` | `org.unit.manage` | `—` | legacy | C1 |
| 108 | `POST` | `/v1/org-units/{id}/move` | `org.unit.manage` | `—` | legacy | C1 |
| 109 | `POST` | `/v1/overtime/records/{recordId}/settlements` | `time.overtime.settle` | `—` | legacy | C2 |
| 110 | `GET` | `/v1/payroll/adjustments` | `payroll.compensation.read` | `LEGAL_ENTITY` | kernel | — |
| 111 | `POST` | `/v1/payroll/adjustments` | `payroll.salary-adjustment.manage` | `LEGAL_ENTITY` | kernel | — |
| 112 | `GET` | `/v1/payroll/employee-plans` | `payroll.compensation.read` | `LEGAL_ENTITY` | kernel | — |
| 113 | `POST` | `/v1/payroll/employee-plans` | `payroll.compensation.manage` | `LEGAL_ENTITY` | kernel | — |
| 114 | `GET` | `/v1/payroll/items` | `payroll.run.read` | `LEGAL_ENTITY` | legacy | B5 |
| 115 | `POST` | `/v1/payroll/items` | `payroll.run.prepare` | `LEGAL_ENTITY` | legacy | B5 |
| 116 | `GET` | `/v1/payroll/payslips` | `payroll.payslip.read.self` | `SELF` | legacy | B5 |
| 117 | `GET` | `/v1/payroll/payslips/{id}` | `payroll.payslip.read.self` | `SELF` | legacy | B5 |
| 118 | `GET` | `/v1/payroll/runs` | `payroll.run.read` | `LEGAL_ENTITY` | legacy | B5 |
| 119 | `POST` | `/v1/payroll/runs` | `payroll.run.prepare` | `LEGAL_ENTITY` | legacy | B5 |
| 120 | `GET` | `/v1/payroll/runs/{id}` | `payroll.run.read` | `PAYROLL_RUN` | legacy | B5 |
| 121 | `POST` | `/v1/payroll/runs/{id}/approve` | `payroll.run.approve` | `PAYROLL_RUN` | legacy | B5 |
| 122 | `POST` | `/v1/payroll/runs/{id}/calculate` | `payroll.run.prepare` | `PAYROLL_RUN` | legacy | B5 |
| 123 | `POST` | `/v1/payroll/runs/{id}/cancel` | `payroll.run.prepare` | `PAYROLL_RUN` | legacy | B5 |
| 124 | `POST` | `/v1/payroll/runs/{id}/close` | `payroll.run.close` | `PAYROLL_RUN` | legacy | B5 |
| 125 | `POST` | `/v1/payroll/runs/{id}/corrections` | `payroll.run.correct` | `PAYROLL_RUN` | legacy | B5 |
| 126 | `GET` | `/v1/payroll/runs/{id}/employees` | `payroll.run.read` | `PAYROLL_RUN` | legacy | B5 |
| 127 | `GET` | `/v1/payroll/runs/{id}/exports/bank` | `payroll.export` | `PAYROLL_RUN` | legacy | B5 |
| 128 | `GET` | `/v1/payroll/runs/{id}/exports/tax` | `payroll.export` | `PAYROLL_RUN` | legacy | B5 |
| 129 | `GET` | `/v1/payroll/runs/{id}/inputs` | `payroll.run.read` | `PAYROLL_RUN` | legacy | B5 |
| 130 | `POST` | `/v1/payroll/runs/{id}/lock-inputs` | `payroll.run.prepare` | `PAYROLL_RUN` | legacy | B5 |
| 131 | `POST` | `/v1/payroll/runs/{id}/mark-paid` | `payroll.run.close` | `PAYROLL_RUN` | legacy | B5 |
| 132 | `POST` | `/v1/payroll/runs/{id}/publish` | `payroll.run.publish` | `PAYROLL_RUN` | legacy | B5 |
| 133 | `GET` | `/v1/payroll/runs/{id}/reconciliation` | `payroll.run.read` | `PAYROLL_RUN` | legacy | B5 |
| 134 | `POST` | `/v1/payroll/runs/{id}/reopen` | `payroll.run.reopen` | `PAYROLL_RUN` | legacy | B5 |
| 135 | `POST` | `/v1/payroll/runs/{id}/review` | `payroll.run.review` | `PAYROLL_RUN` | legacy | B5 |
| 136 | `GET` | `/v1/payroll/schemes` | `payroll.run.read` | `LEGAL_ENTITY` | legacy | B5 |
| 137 | `POST` | `/v1/payroll/schemes` | `payroll.run.prepare` | `LEGAL_ENTITY` | legacy | B5 |
| 138 | `GET` | `/v1/payroll/statutory-parameters` | `payroll.parameters.read` | `LEGAL_ENTITY` | legacy | B5 |
| 139 | `POST` | `/v1/payroll/statutory-parameters` | `payroll.parameters.manage` | `LEGAL_ENTITY` | legacy | B5 |
| 140 | `GET` | `/v1/performance/calibration` | `performance.calibration.manage` | `—` | kernel | — |
| 141 | `GET` | `/v1/performance/cycles` | `performance.cycle.read` | `—` | legacy | B3 |
| 142 | `POST` | `/v1/performance/cycles` | `performance.cycle.manage` | `—` | legacy | B3 |
| 143 | `POST` | `/v1/performance/cycles/{id}/publish` | `performance.result.publish` | `—` | kernel | — |
| 144 | `GET` | `/v1/performance/feedback` | `performance.feedback.manage` | `—` | kernel | — |
| 145 | `POST` | `/v1/performance/feedback` | `performance.feedback.manage` | `—` | kernel | — |
| 146 | `GET` | `/v1/performance/goals` | `performance.goal.manage` | `—` | kernel | — |
| 147 | `POST` | `/v1/performance/goals` | `performance.goal.manage` | `—` | kernel | — |
| 148 | `GET` | `/v1/performance/indicators` | `performance.indicator.manage` | `—` | kernel | — |
| 149 | `POST` | `/v1/performance/indicators` | `performance.indicator.manage` | `—` | kernel | — |
| 150 | `GET` | `/v1/performance/reports` | `performance.report.read` | `—` | kernel | — |
| 151 | `GET` | `/v1/performance/results` | `performance.result.publish` | `—` | kernel | — |
| 152 | `GET` | `/v1/performance/reviews` | `performance.review.manage` | `—` | legacy | B3 |
| 153 | `POST` | `/v1/performance/reviews` | `performance.review.manage` | `—` | legacy | B3 |
| 154 | `POST` | `/v1/performance/reviews/{id}/appeals` | `performance.appeal.manage` | `—` | legacy | B3 |
| 155 | `POST` | `/v1/performance/reviews/{id}/calibration` | `performance.calibration.manage` | `—` | kernel | — |
| 156 | `POST` | `/v1/performance/reviews/{id}/submit` | `performance.review.submit` | `—` | legacy | B3 |
| 157 | `GET` | `/v1/performance/templates` | `performance.cycle.read` | `—` | legacy | B3 |
| 158 | `POST` | `/v1/performance/templates` | `performance.cycle.manage` | `—` | legacy | B3 |
| 159 | `POST` | `/v1/persons` | `people.employee.manage` | `—` | legacy | E3 |
| 160 | `GET` | `/v1/platform/features` | `platform.feature.manage` | `—` | legacy | D1 |
| 161 | `PUT` | `/v1/platform/features/{flag}` | `platform.feature.manage` | `—` | legacy | D1 |
| 162 | `GET` | `/v1/positions` | `org.position.read` | `—` | legacy | C1 |
| 163 | `POST` | `/v1/positions` | `org.position.manage` | `—` | legacy | C1 |
| 164 | `GET` | `/v1/positions/{id}` | `org.position.read` | `—` | legacy | C1 |
| 165 | `PATCH` | `/v1/positions/{id}` | `org.position.manage` | `—` | legacy | C1 |
| 166 | `GET` | `/v1/recruitment/candidates` | `recruitment.candidate.read` | `—` | legacy | B4 |
| 167 | `POST` | `/v1/recruitment/candidates` | `recruitment.candidate.manage` | `—` | legacy | B4 |
| 168 | `POST` | `/v1/recruitment/candidates/{id}/interviews` | `recruitment.interview.manage` | `—` | legacy | B4 |
| 169 | `GET` | `/v1/recruitment/offers` | `recruitment.offer.manage` | `—` | legacy | B4 |
| 170 | `POST` | `/v1/recruitment/offers` | `recruitment.offer.manage` | `—` | legacy | B4 |
| 171 | `POST` | `/v1/recruitment/offers/{id}/accept` | `recruitment.offer.accept` | `—` | legacy | B4 |
| 172 | `GET` | `/v1/recruitment/requisitions` | `recruitment.requisition.read` | `—` | legacy | B4 |
| 173 | `POST` | `/v1/recruitment/requisitions` | `recruitment.requisition.manage` | `LEGAL_ENTITY` | legacy | B4 |
| 174 | `GET` | `/v1/recruitment/requisitions/{id}/openings` | `recruitment.requisition.read` | `—` | legacy | B4 |
| 175 | `POST` | `/v1/recruitment/requisitions/{id}/openings` | `recruitment.requisition.manage` | `—` | legacy | B4 |
| 176 | `GET` | `/v1/reporting/dashboard` | `reporting.dashboard.read` | `—` | legacy | D4 |
| 177 | `GET` | `/v1/reporting/dashboard/metrics/{metric}` | `reporting.dashboard.read` | `—` | legacy | D4 |
| 178 | `GET` | `/v1/reporting/definitions` | `reporting.dashboard.read` | `—` | legacy | D4 |
| 179 | `POST` | `/v1/reporting/exports` | `reporting.export` | `LEGAL_ENTITY` | legacy | D4 |
| 180 | `GET` | `/v1/reporting/exports/{jobId}/download` | `reporting.export` | `—` | legacy | D4 |
| 181 | `POST` | `/v1/reporting/run` | `reporting.dashboard.read` | `—` | legacy | D4 |
| 182 | `GET` | `/v1/roles` | `iam.role.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 183 | `GET` | `/v1/tasks/my` | `workflow.case.read` | `—` | legacy | E3 |
| 184 | `GET` | `/v1/time/attendance` | `time.attendance.read` | `—` | legacy | C2 |
| 185 | `POST` | `/v1/time/attendance/closings` | `time.attendance.manage` | `—` | legacy | C2 |
| 186 | `POST` | `/v1/time/attendance/closings/{id}/reopen` | `time.attendance.reopen` | `—` | legacy | C2 |
| 187 | `POST` | `/v1/time/attendance/corrections` | `time.attendance.correct.request` | `—` | legacy | C2 |
| 188 | `POST` | `/v1/time/attendance/recalculate` | `time.attendance.manage` | `—` | legacy | C2 |
| 189 | `GET` | `/v1/time/attendance/{employment_id}` | `time.attendance.read` | `—` | legacy | C2 |
| 190 | `GET` | `/v1/time/calendars/{legal_entity_id}` | `time.calendar.read` | `—` | legacy | D1 |
| 191 | `GET` | `/v1/time/leave/balances` | `time.leave.read` | `—` | legacy | E3 |
| 192 | `GET` | `/v1/time/leave/ledger` | `time.leave.ledger.read` | `—` | legacy | E3 |
| 193 | `GET` | `/v1/time/leave/policies` | `time.leave.read` | `—` | legacy | E3 |
| 194 | `POST` | `/v1/time/leave/preview` | `time.leave.read` | `—` | legacy | E3 |
| 195 | `GET` | `/v1/time/leave/requests` | `time.leave.read` | `—` | legacy | E3 |
| 196 | `POST` | `/v1/time/leave/requests` | `time.leave.request` | `—` | legacy | E3 |
| 197 | `POST` | `/v1/time/leave/requests/{caseId}/cancel` | `time.leave.request.cancel` | `—` | legacy | E3 |
| 198 | `GET` | `/v1/time/leave/types` | `time.leave.read` | `—` | legacy | E3 |
| 199 | `GET` | `/v1/time/overtime/policies` | `time.overtime.read` | `—` | legacy | B2 |
| 200 | `GET` | `/v1/time/overtime/records` | `time.overtime.read` | `—` | legacy | B2 |
| 201 | `GET` | `/v1/time/overtime/requests` | `time.overtime.read` | `—` | kernel | — |
| 202 | `POST` | `/v1/time/overtime/requests` | `time.overtime.request` | `—` | kernel | — |
| 203 | `POST` | `/v1/time/overtime/requests/{id}/confirm` | `time.overtime.confirm` | `—` | kernel | — |
| 204 | `GET` | `/v1/time/overtime/timeoff-balances` | `time.timeoff.read` | `—` | kernel | — |
| 205 | `POST` | `/v1/time/punches/import` | `time.punch.import` | `—` | legacy | C2 |
| 206 | `GET` | `/v1/time/shifts` | `time.shift.read` | `—` | legacy | C2 |
| 207 | `POST` | `/v1/training/enrollments` | `training.enrollment.manage` | `—` | legacy | D3 |
| 208 | `POST` | `/v1/training/enrollments/{id}/complete` | `training.completion.manage` | `—` | legacy | D3 |
| 209 | `GET` | `/v1/training/programs` | `training.program.read` | `—` | legacy | D3 |
| 210 | `POST` | `/v1/training/programs` | `training.program.manage` | `—` | legacy | D3 |
| 211 | `GET` | `/v1/training/report` | `training.report.read` | `—` | legacy | D3 |
| 212 | `GET` | `/v1/training/sessions` | `training.session.manage` | `—` | legacy | D3 |
| 213 | `POST` | `/v1/training/sessions` | `training.session.manage` | `—` | legacy | D3 |
| 214 | `GET` | `/v1/travel/requests` | `time.travel.read` | `—` | legacy | C3 |
| 215 | `POST` | `/v1/travel/requests` | `time.travel.request` | `—` | legacy | C3 |
| 216 | `POST` | `/v1/travel/requests/{id}/cancel` | `time.travel.request` | `—` | legacy | C3 |
| 217 | `GET` | `/v1/users` | `iam.user.read` | `LEGAL_ENTITY` | legacy | D1 |
| 218 | `POST` | `/v1/users` | `iam.user.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 219 | `PATCH` | `/v1/users/{id}` | `iam.user.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 220 | `GET` | `/v1/users/{id}/bindings` | `iam.binding.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 221 | `POST` | `/v1/users/{id}/bindings` | `iam.binding.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 222 | `POST` | `/v1/users/{id}/roles` | `iam.role.manage` | `LEGAL_ENTITY` | legacy | D1 |
| 223 | `GET` | `/v1/workflow/templates` | `workflow.case.read` | `—` | legacy | E3 |
| 224 | `POST` | `/v1/workflow/templates` | `workflow.case.admin` | `—` | legacy | E3 |

## 2. 迁移规则

1. 每个 `legacy` 路由必须在 `迁移 Task` 列指向 B1–B5 / C1–C4 / D1–D4 / E3 之一。
2. 集群 Task 完成后，该集群路由必须改为 `kernel`（CommandRunner 或 ProjectionResponder）。
3. 任何新增 `/v1` 路由若未出现在本台账，`LegacyRouteInventoryTest` 失败。
4. 新增路由必须是 `kernel`；`LegacyRouteInventoryTest` 用冻结清单阻止“新增即 legacy”。
5. `E3` 仅用于无集群归属的既有端点收敛（auth/session、cases、Me、leave 读写等）。

## 3. E3 范围（显式列出，避免变成垃圾桶）

| 现有端点族 | 为何归 E3 | 迁移方式 |
|---|---|---|
| `/v1/auth/*`、`/v1/me/*`、`/v1/bindings` | IAM 会话与身份治理 | D1 完成后改 ProjectionResponder；会话路由保持专用中间件 |
| `/v1/cases*`、`/v1/delegations*`、`/v1/tasks/my`、`/v1/effects` | Workflow 既有端点 | E3 统一改 ProjectionResponder/CommandRunner |
| `/v1/leave/*`、`/v1/time/leave/*` | 假期账本既有端点 | E3 迁移；K5 只新增调休投影 |
| `/v1/employees*`、`/v1/employments*`、`/v1/persons` | People 主档既有读写 | C1 迁移岗位与组织；员工主档 E3 收敛 |
| `/v1/platform/features` | Feature flag 管理 | D1 迁移 |
