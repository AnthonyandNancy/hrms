# Volume 1 Action Gaps — 2026-11-26

Status: explicit, per-operation gaps. Machine gate reads the JSON file only.

## Measurement

- capability-guarded OpenAPI writes: **173**
- labelled before Task 6 spec update: **173/173**
- missing x-action-label when this task started: **173**
- missing x-action-label now: **0**
- capability-guarded writes carried by current pages: **3**
- explicit gap entries: **170**

Command:

```bash
node --input-type=module -e "import {readFileSync} from 'node:fs'; import YAML from 'yaml'; const d=YAML.parse(readFileSync('docs/openapi/openapi.yaml','utf8')); const ops=[]; for (const [p,i] of Object.entries(d.paths??{})) for (const m of ['post','put','patch','delete']) { const o=i?.[m]; if (o?.['x-capability']) ops.push([m.toUpperCase()+' '+p, o['x-action-label']]); } console.log('guarded='+ops.length, 'labelled='+ops.filter(([,l])=>typeof l==='string'&&l.trim()).length, 'missing='+ops.filter(([,l])=>!(typeof l==='string'&&l.trim())).length)"
```

Public, unguarded writes (not actions): `POST /v1/auth/login`, `POST /v1/auth/mfa/challenge`.

## Owners

| Owner | Gap entries |
|---|---:|
| Volume 2 Task 10 | 1 |
| Volume 2 Task 6 | 4 |
| Volume 2 Task 7 | 6 |
| Volume 2 Task 8 | 5 |
| Volume 2 Task 9 | 6 |
| Volume 3 Task 1 | 5 |
| Volume 3 Task 10 | 5 |
| Volume 3 Task 11 | 8 |
| Volume 3 Task 12 | 16 |
| Volume 3 Task 13 | 10 |
| Volume 3 Task 14 | 11 |
| Volume 3 Task 15 | 10 |
| Volume 3 Task 16 | 19 |
| Volume 3 Task 17 | 16 |
| Volume 3 Task 4 | 17 |
| Volume 3 Task 5 | 3 |
| Volume 3 Task 6 | 4 |
| Volume 3 Task 7 | 10 |
| Volume 3 Task 8 | 7 |
| Volume 3 Task 9 | 7 |

## Entries

| API | Owner | Reason |
|---|---|---|
| `DELETE /v1/bindings/{id}` | Volume 2 Task 6 | DELETE /v1/bindings/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 6. |
| `DELETE /v1/delegations/{id}` | Volume 3 Task 17 | DELETE /v1/delegations/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `DELETE /v1/documents/{document_id}` | Volume 3 Task 4 | DELETE /v1/documents/{document_id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `DELETE /v1/employees/{employment_id}` | Volume 3 Task 1 | DELETE /v1/employees/{employment_id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 1. |
| `DELETE /v1/employment-contracts/{id}` | Volume 3 Task 8 | DELETE /v1/employment-contracts/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 8. |
| `DELETE /v1/reporting/subscriptions/{subscriptionId}` | Volume 3 Task 16 | DELETE /v1/reporting/subscriptions/{subscriptionId} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `DELETE /v1/system/menus/{page_code}` | Volume 2 Task 8 | DELETE /v1/system/menus/{page_code} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 8. |
| `DELETE /v1/users/{id}/roles/{roleCode}` | Volume 2 Task 7 | DELETE /v1/users/{id}/roles/{roleCode} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 7. |
| `PATCH /v1/employees/{employmentId}/certificates/{id}` | Volume 3 Task 4 | PATCH /v1/employees/{employmentId}/certificates/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `PATCH /v1/employees/{employmentId}/change_requests/{id}` | Volume 3 Task 4 | PATCH /v1/employees/{employmentId}/change_requests/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `PATCH /v1/employees/{employmentId}/contacts/{id}` | Volume 3 Task 4 | PATCH /v1/employees/{employmentId}/contacts/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `PATCH /v1/employees/{employmentId}/education/{id}` | Volume 3 Task 4 | PATCH /v1/employees/{employmentId}/education/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `PATCH /v1/employees/{employmentId}/experiences/{id}` | Volume 3 Task 4 | PATCH /v1/employees/{employmentId}/experiences/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `PATCH /v1/employees/{employmentId}/imports/{id}` | Volume 3 Task 4 | PATCH /v1/employees/{employmentId}/imports/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `PATCH /v1/onboarding-tasks/{id}` | Volume 3 Task 7 | PATCH /v1/onboarding-tasks/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `PATCH /v1/positions/{id}` | Volume 3 Task 6 | PATCH /v1/positions/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 6. |
| `PATCH /v1/reporting/subscriptions/{subscriptionId}` | Volume 3 Task 16 | PATCH /v1/reporting/subscriptions/{subscriptionId} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `PATCH /v1/system/dictionaries/{id}` | Volume 3 Task 17 | PATCH /v1/system/dictionaries/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `PATCH /v1/system/menus/{page_code}` | Volume 2 Task 8 | PATCH /v1/system/menus/{page_code} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 8. |
| `PATCH /v1/users/{id}` | Volume 2 Task 6 | PATCH /v1/users/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 6. |
| `POST /v1/auth/break-glass` | Volume 2 Task 9 | POST /v1/auth/break-glass has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 9. |
| `POST /v1/auth/break-glass/{id}/revoke` | Volume 2 Task 9 | POST /v1/auth/break-glass/{id}/revoke has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 9. |
| `POST /v1/auth/logout` | Volume 2 Task 9 | POST /v1/auth/logout has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 9. |
| `POST /v1/auth/mfa/step-up` | Volume 2 Task 9 | POST /v1/auth/mfa/step-up has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 9. |
| `POST /v1/auth/password` | Volume 2 Task 10 | POST /v1/auth/password has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 10. |
| `POST /v1/auth/webauthn/assert/options` | Volume 2 Task 9 | POST /v1/auth/webauthn/assert/options has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 9. |
| `POST /v1/auth/webauthn/register/options` | Volume 2 Task 9 | POST /v1/auth/webauthn/register/options has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 9. |
| `POST /v1/benefits/enrollments` | Volume 3 Task 17 | POST /v1/benefits/enrollments has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/benefits/plans` | Volume 3 Task 17 | POST /v1/benefits/plans has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/benefits/transactions` | Volume 3 Task 17 | POST /v1/benefits/transactions has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/cases` | Volume 3 Task 17 | POST /v1/cases has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/cases/{caseId}/decisions` | Volume 3 Task 17 | POST /v1/cases/{caseId}/decisions has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/cases/{id}/submit` | Volume 3 Task 17 | POST /v1/cases/{id}/submit has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/cases/{id}/withdraw` | Volume 3 Task 17 | POST /v1/cases/{id}/withdraw has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/contract-templates` | Volume 3 Task 8 | POST /v1/contract-templates has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 8. |
| `POST /v1/contract-templates/{id}/publish` | Volume 3 Task 8 | POST /v1/contract-templates/{id}/publish has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 8. |
| `POST /v1/delegations` | Volume 3 Task 17 | POST /v1/delegations has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/departments/{id}/members` | Volume 3 Task 5 | POST /v1/departments/{id}/members has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 5. |
| `POST /v1/effects/{id}/retry` | Volume 3 Task 17 | POST /v1/effects/{id}/retry has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/employees/{employment_id}/restore` | Volume 3 Task 1 | POST /v1/employees/{employment_id}/restore has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 1. |
| `POST /v1/employees/{employmentId}/certificates` | Volume 3 Task 4 | POST /v1/employees/{employmentId}/certificates has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employees/{employmentId}/change_requests` | Volume 3 Task 4 | POST /v1/employees/{employmentId}/change_requests has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employees/{employmentId}/change_requests/{id}/submit` | Volume 3 Task 4 | POST /v1/employees/{employmentId}/change_requests/{id}/submit has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employees/{employmentId}/contacts` | Volume 3 Task 4 | POST /v1/employees/{employmentId}/contacts has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employees/{employmentId}/education` | Volume 3 Task 4 | POST /v1/employees/{employmentId}/education has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employees/{employmentId}/experiences` | Volume 3 Task 4 | POST /v1/employees/{employmentId}/experiences has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employees/{employmentId}/imports` | Volume 3 Task 4 | POST /v1/employees/{employmentId}/imports has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employment-contracts/{id}/amendments` | Volume 3 Task 8 | POST /v1/employment-contracts/{id}/amendments has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 8. |
| `POST /v1/employment-contracts/{id}/restore` | Volume 3 Task 8 | POST /v1/employment-contracts/{id}/restore has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 8. |
| `POST /v1/employment-contracts/{id}/terminate` | Volume 3 Task 8 | POST /v1/employment-contracts/{id}/terminate has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 8. |
| `POST /v1/employments` | Volume 3 Task 1 | POST /v1/employments has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 1. |
| `POST /v1/employments/{employment_id}/assignments` | Volume 3 Task 1 | POST /v1/employments/{employment_id}/assignments has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 1. |
| `POST /v1/employments/{employment_id}/document-files` | Volume 3 Task 4 | POST /v1/employments/{employment_id}/document-files has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employments/{employment_id}/documents` | Volume 3 Task 4 | POST /v1/employments/{employment_id}/documents has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employments/{employment_id}/terms` | Volume 3 Task 4 | POST /v1/employments/{employment_id}/terms has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 4. |
| `POST /v1/employments/{employment_id}/transfer` | Volume 3 Task 7 | POST /v1/employments/{employment_id}/transfer has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/employments/{employmentId}/contracts` | Volume 3 Task 8 | POST /v1/employments/{employmentId}/contracts has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 8. |
| `POST /v1/engagement/notice-templates` | Volume 3 Task 16 | POST /v1/engagement/notice-templates has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices` | Volume 3 Task 16 | POST /v1/engagement/notices has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices/{notice}/acknowledge` | Volume 3 Task 16 | POST /v1/engagement/notices/{notice}/acknowledge has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices/{notice}/archive` | Volume 3 Task 16 | POST /v1/engagement/notices/{notice}/archive has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices/{notice}/decision` | Volume 3 Task 16 | POST /v1/engagement/notices/{notice}/decision has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices/{notice}/publish` | Volume 3 Task 16 | POST /v1/engagement/notices/{notice}/publish has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices/{notice}/restore` | Volume 3 Task 16 | POST /v1/engagement/notices/{notice}/restore has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices/{notice}/retract` | Volume 3 Task 16 | POST /v1/engagement/notices/{notice}/retract has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notices/{notice}/submit` | Volume 3 Task 16 | POST /v1/engagement/notices/{notice}/submit has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/engagement/notifications/{notification}/read` | Volume 3 Task 16 | POST /v1/engagement/notifications/{notification}/read has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/leave/requests` | Volume 3 Task 10 | POST /v1/leave/requests has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 10. |
| `POST /v1/leave/requests/{caseId}/cancel` | Volume 3 Task 10 | POST /v1/leave/requests/{caseId}/cancel has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 10. |
| `POST /v1/lifecycle/contract-renewals` | Volume 3 Task 7 | POST /v1/lifecycle/contract-renewals has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/lifecycle/offboardings` | Volume 3 Task 7 | POST /v1/lifecycle/offboardings has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/lifecycle/onboarding` | Volume 3 Task 7 | POST /v1/lifecycle/onboarding has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/lifecycle/probation-confirmations` | Volume 3 Task 7 | POST /v1/lifecycle/probation-confirmations has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/lifecycle/rehires` | Volume 3 Task 7 | POST /v1/lifecycle/rehires has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/lifecycle/salary-changes` | Volume 3 Task 7 | POST /v1/lifecycle/salary-changes has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/lifecycle/secondments` | Volume 3 Task 7 | POST /v1/lifecycle/secondments has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/lifecycle/transfers` | Volume 3 Task 7 | POST /v1/lifecycle/transfers has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 7. |
| `POST /v1/notification-templates` | Volume 3 Task 16 | POST /v1/notification-templates has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/notifications/deliveries/{id}/retry` | Volume 3 Task 16 | POST /v1/notifications/deliveries/{id}/retry has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/notifications/dispatch` | Volume 3 Task 16 | POST /v1/notifications/dispatch has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/org-units` | Volume 3 Task 5 | POST /v1/org-units has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 5. |
| `POST /v1/org-units/{id}/move` | Volume 3 Task 5 | POST /v1/org-units/{id}/move has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 5. |
| `POST /v1/overtime/records/{recordId}/settlements` | Volume 3 Task 11 | POST /v1/overtime/records/{recordId}/settlements has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/payroll/adjustments` | Volume 3 Task 12 | POST /v1/payroll/adjustments has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/employee-plans` | Volume 3 Task 12 | POST /v1/payroll/employee-plans has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/items` | Volume 3 Task 12 | POST /v1/payroll/items has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs` | Volume 3 Task 12 | POST /v1/payroll/runs has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/approve` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/approve has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/calculate` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/calculate has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/cancel` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/cancel has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/close` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/close has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/corrections` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/corrections has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/lock-inputs` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/lock-inputs has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/mark-paid` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/mark-paid has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/publish` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/publish has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/reopen` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/reopen has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/runs/{id}/review` | Volume 3 Task 12 | POST /v1/payroll/runs/{id}/review has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/schemes` | Volume 3 Task 12 | POST /v1/payroll/schemes has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/payroll/statutory-parameters` | Volume 3 Task 12 | POST /v1/payroll/statutory-parameters has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 12. |
| `POST /v1/performance/cycles` | Volume 3 Task 13 | POST /v1/performance/cycles has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/cycles/{id}/publish` | Volume 3 Task 13 | POST /v1/performance/cycles/{id}/publish has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/feedback` | Volume 3 Task 13 | POST /v1/performance/feedback has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/goals` | Volume 3 Task 13 | POST /v1/performance/goals has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/indicators` | Volume 3 Task 13 | POST /v1/performance/indicators has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/reviews` | Volume 3 Task 13 | POST /v1/performance/reviews has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/reviews/{id}/appeals` | Volume 3 Task 13 | POST /v1/performance/reviews/{id}/appeals has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/reviews/{id}/calibration` | Volume 3 Task 13 | POST /v1/performance/reviews/{id}/calibration has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/reviews/{id}/submit` | Volume 3 Task 13 | POST /v1/performance/reviews/{id}/submit has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/performance/templates` | Volume 3 Task 13 | POST /v1/performance/templates has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 13. |
| `POST /v1/persons` | Volume 3 Task 1 | POST /v1/persons has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 1. |
| `POST /v1/positions` | Volume 3 Task 6 | POST /v1/positions has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 6. |
| `POST /v1/positions/{id}/requirements` | Volume 3 Task 6 | POST /v1/positions/{id}/requirements has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 6. |
| `POST /v1/positions/grades` | Volume 3 Task 6 | POST /v1/positions/grades has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 6. |
| `POST /v1/recruitment/candidates` | Volume 3 Task 14 | POST /v1/recruitment/candidates has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/candidates/{id}/communications` | Volume 3 Task 14 | POST /v1/recruitment/candidates/{id}/communications has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/candidates/{id}/convert` | Volume 3 Task 14 | POST /v1/recruitment/candidates/{id}/convert has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/candidates/{id}/evaluations` | Volume 3 Task 14 | POST /v1/recruitment/candidates/{id}/evaluations has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/candidates/{id}/interviews` | Volume 3 Task 14 | POST /v1/recruitment/candidates/{id}/interviews has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/candidates/{id}/talent-pool` | Volume 3 Task 14 | POST /v1/recruitment/candidates/{id}/talent-pool has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/candidates/imports` | Volume 3 Task 14 | POST /v1/recruitment/candidates/imports has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/offers` | Volume 3 Task 14 | POST /v1/recruitment/offers has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/offers/{id}/accept` | Volume 3 Task 14 | POST /v1/recruitment/offers/{id}/accept has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/requisitions` | Volume 3 Task 14 | POST /v1/recruitment/requisitions has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/recruitment/requisitions/{id}/openings` | Volume 3 Task 14 | POST /v1/recruitment/requisitions/{id}/openings has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 14. |
| `POST /v1/reporting/definitions` | Volume 3 Task 16 | POST /v1/reporting/definitions has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/reporting/exports` | Volume 3 Task 16 | POST /v1/reporting/exports has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/reporting/run` | Volume 3 Task 16 | POST /v1/reporting/run has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/reporting/subscriptions` | Volume 3 Task 16 | POST /v1/reporting/subscriptions has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 16. |
| `POST /v1/roles` | Volume 2 Task 7 | POST /v1/roles has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 7. |
| `POST /v1/roles/{id}/copy` | Volume 2 Task 7 | POST /v1/roles/{id}/copy has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 7. |
| `POST /v1/system/calendars/{legal_entity_id}/publish` | Volume 3 Task 17 | POST /v1/system/calendars/{legal_entity_id}/publish has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/system/dictionaries` | Volume 3 Task 17 | POST /v1/system/dictionaries has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `POST /v1/system/menus` | Volume 2 Task 8 | POST /v1/system/menus has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 8. |
| `POST /v1/time/attendance/closings` | Volume 3 Task 9 | POST /v1/time/attendance/closings has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 9. |
| `POST /v1/time/attendance/closings/{id}/reopen` | Volume 3 Task 9 | POST /v1/time/attendance/closings/{id}/reopen has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 9. |
| `POST /v1/time/attendance/corrections` | Volume 3 Task 9 | POST /v1/time/attendance/corrections has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 9. |
| `POST /v1/time/attendance/recalculate` | Volume 3 Task 9 | POST /v1/time/attendance/recalculate has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 9. |
| `POST /v1/time/attendance/rules` | Volume 3 Task 9 | POST /v1/time/attendance/rules has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 9. |
| `POST /v1/time/leave/preview` | Volume 3 Task 10 | POST /v1/time/leave/preview has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 10. |
| `POST /v1/time/leave/requests` | Volume 3 Task 10 | POST /v1/time/leave/requests has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 10. |
| `POST /v1/time/leave/requests/{caseId}/cancel` | Volume 3 Task 10 | POST /v1/time/leave/requests/{caseId}/cancel has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 10. |
| `POST /v1/time/overtime/requests` | Volume 3 Task 11 | POST /v1/time/overtime/requests has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/time/overtime/requests/{id}/confirm` | Volume 3 Task 11 | POST /v1/time/overtime/requests/{id}/confirm has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/time/punches/import` | Volume 3 Task 9 | POST /v1/time/punches/import has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 9. |
| `POST /v1/time/shift-schedules` | Volume 3 Task 9 | POST /v1/time/shift-schedules has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 9. |
| `POST /v1/training/courses` | Volume 3 Task 15 | POST /v1/training/courses has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/enrollments` | Volume 3 Task 15 | POST /v1/training/enrollments has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/enrollments/{id}/complete` | Volume 3 Task 15 | POST /v1/training/enrollments/{id}/complete has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/exams` | Volume 3 Task 15 | POST /v1/training/exams has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/exams/{id}/publish` | Volume 3 Task 15 | POST /v1/training/exams/{id}/publish has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/feedback` | Volume 3 Task 15 | POST /v1/training/feedback has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/plans` | Volume 3 Task 15 | POST /v1/training/plans has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/programs` | Volume 3 Task 15 | POST /v1/training/programs has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/sessions` | Volume 3 Task 15 | POST /v1/training/sessions has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/training/sessions/{id}/checkins` | Volume 3 Task 15 | POST /v1/training/sessions/{id}/checkins has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 15. |
| `POST /v1/travel/expenses` | Volume 3 Task 11 | POST /v1/travel/expenses has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/travel/policies` | Volume 3 Task 11 | POST /v1/travel/policies has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/travel/requests` | Volume 3 Task 11 | POST /v1/travel/requests has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/travel/requests/{id}/approve` | Volume 3 Task 11 | POST /v1/travel/requests/{id}/approve has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/travel/requests/{id}/cancel` | Volume 3 Task 11 | POST /v1/travel/requests/{id}/cancel has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 11. |
| `POST /v1/users` | Volume 2 Task 6 | POST /v1/users has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 6. |
| `POST /v1/users/{id}/bindings` | Volume 2 Task 6 | POST /v1/users/{id}/bindings has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 6. |
| `POST /v1/users/{id}/roles` | Volume 2 Task 7 | POST /v1/users/{id}/roles has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 7. |
| `POST /v1/workflow/templates` | Volume 3 Task 17 | POST /v1/workflow/templates has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `PUT /v1/platform/features/{flag}` | Volume 3 Task 17 | PUT /v1/platform/features/{flag} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
| `PUT /v1/roles/{id}/capabilities` | Volume 2 Task 7 | PUT /v1/roles/{id}/capabilities has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 7. |
| `PUT /v1/roles/{id}/pages` | Volume 2 Task 7 | PUT /v1/roles/{id}/pages has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 7. |
| `PUT /v1/system/data-scopes/{id}` | Volume 2 Task 8 | PUT /v1/system/data-scopes/{id} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 8. |
| `PUT /v1/system/field-policies/{field_key}` | Volume 2 Task 8 | PUT /v1/system/field-policies/{field_key} has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 2 Task 8. |
| `PUT /v1/system/parameters` | Volume 3 Task 17 | PUT /v1/system/parameters has no page mapping in PAGE_REGISTRY in Volume 1; tracked for Volume 3 Task 17. |
