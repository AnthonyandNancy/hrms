# FactoryHR V2.1 Capability Inventory

- Source: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php` (code-owned)
- Generation command: `node scripts/collect_v2_baseline.mjs`
- Capability count: 163

| Capability code | Resource | Actions | Anchor types | Field profile max | Risk | SoD group | Description |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `iam.user.read` | `user` | `read` | `SELF`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `identity_governance` | Read user accounts within an anchor. |
| `iam.user.manage` | `user` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `critical` | `identity_governance` | Create and update user accounts. |
| `iam.role.manage` | `role` | `read`, `create`, `update` | `COMPANY` | `P1_INTERNAL` | `critical` | `identity_governance` | Manage role assignments and templates. |
| `iam.binding.manage` | `authority_binding` | `read`, `create`, `delete` | `COMPANY` | `P1_INTERNAL` | `critical` | `identity_governance` | Manage authority bindings. |
| `iam.session.manage` | `session` | `read`, `revoke` | `SELF` | `P0_PUBLIC` | `low` | — | Intrinsic self-service access to the caller own session; cannot be delegated. |
| `iam.audit.read` | `auth_event` | `read`, `export` | `COMPANY` | `P2_SENSITIVE` | `high` | `identity_audit` | Read authentication and authorization audit evidence. |
| `iam.menu.manage` | `menu` | `create`, `update`, `delete`, `sort` | `COMPANY` | `P1_INTERNAL` | `high` | `identity_governance` | Manage the code-declared menu tree, ordering and enabled state. |
| `iam.permission.read` | `permission_resource` | `read` | `COMPANY` | `P1_INTERNAL` | `medium` | `identity_governance` | Read permission resources and detect role/page coverage gaps. |
| `iam.scope.manage` | `data_scope` | `create`, `update`, `delete` | `COMPANY` | `P2_SENSITIVE` | `critical` | `identity_governance` | Configure whitelisted data scopes and preview their membership. |
| `iam.field.manage` | `field_policy` | `create`, `update` | `COMPANY` | `P2_SENSITIVE` | `critical` | `identity_governance` | Configure P0-P3 field visibility, masking and export policies. |
| `org.unit.read` | `org_unit` | `read` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read organization units. |
| `org.unit.manage` | `org_unit` | `create`, `update`, `delete` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | — | Manage organization units. |
| `org.tree.read` | `org_tree` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P0_PUBLIC` | `low` | — | Read the organization tree. |
| `org.unit.export` | `org_unit` | `export` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | `org_export` | Export the organization structure with a field whitelist. |
| `org.position.read` | `position` | `read` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read position catalogues, grades and headcount occupancy. |
| `org.position.manage` | `position` | `create`, `update`, `delete` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `org_position` | Create, version and disable positions and their headcount limits. |
| `org.position.export` | `position` | `export` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | `org_export` | Export positions and headcount within the caller anchor. |
| `benefit.read` | `benefit_plan` | `read` | `SELF`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Read statutory base plans, benefit elections and benefit ledgers. |
| `benefit.manage` | `benefit_plan` | `create`, `update`, `approve` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `critical` | `benefit_admin` | Manage benefit plans, statutory bases and monthly enrollment movements. |
| `people.employee.read` | `employee` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `CASE`, `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Read employee records; fields are projected per binding. |
| `people.employee.manage` | `employee` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `people_manage` | Create and update employee records. |
| `people.assignment.manage` | `assignment` | `create`, `update`, `end` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | — | Manage effective-dated assignments. |
| `people.contract.read` | `contract` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Read employment contracts. |
| `people.contract.manage` | `contract` | `create`, `update`, `terminate` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Manage employment contracts. |
| `people.contract.delete` | `contract` | `delete` | `COMPANY` | `P2_SENSITIVE` | `high` | `people_contract` | Soft-delete a labour contract while preserving its version history. |
| `people.contract.restore` | `contract` | `restore` | `COMPANY` | `P2_SENSITIVE` | `high` | `people_contract` | Restore a soft-deleted labour contract. |
| `people.employee.archive` | `employee` | `archive` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `people_employee` | Archive an employee without deleting historical records. |
| `people.employee.restore` | `employee` | `restore` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `people_employee` | Restore an archived employee. |
| `people.employee.export` | `employee` | `export` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `people_export` | Export employee data with an explicit field whitelist and row cap. |
| `people.contract.export` | `contract` | `export` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `people_export` | Export contract metadata subject to P2/P3 field policy constraints. |
| `people.document.download` | `document` | `download` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `people_export` | Download an employee document through a short-lived audited token. |
| `workflow.case.read` | `case` | `read` | `SELF`, `MANAGED`, `CASE`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read workflow cases visible through a membership anchor. |
| `lifecycle.case.read` | `lifecycle_case` | `read` | `SELF`, `MANAGED`, `CASE`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read lifecycle cases and onboarding checklists. |
| `lifecycle.onboarding.manage` | `lifecycle_case` | `create` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `lifecycle_people` | Start onboarding cases. |
| `lifecycle.probation.manage` | `employment` | `confirm` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `lifecycle_people` | Confirm probation cases. |
| `lifecycle.transfer.manage` | `employment` | `transfer` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `lifecycle_people` | Request assignment changes and transfers. |
| `lifecycle.terms.manage` | `employment_terms` | `version` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P3_RESTRICTED` | `critical` | `lifecycle_people` | Request salary and terms changes. |
| `lifecycle.offboard.manage` | `employment` | `terminate` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `critical` | `lifecycle_people` | Start offboarding cases with access revocation. |
| `lifecycle.contract.manage` | `contract` | `renew` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `lifecycle_people` | Renew or change employment contracts. |
| `lifecycle.rehire.manage` | `employment` | `rehire` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `lifecycle_people` | Rehire a former employee as a new employment. |
| `lifecycle.checklist.manage` | `lifecycle_task` | `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Complete or waive onboarding/offboarding checklist tasks. |
| `workflow.case.create` | `case` | `create` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Create a workflow case. |
| `workflow.case.decide` | `case` | `decide` | `CASE`, `MANAGED` | `P1_INTERNAL` | `high` | — | Record a decision on an assigned case. |
| `workflow.case.admin` | `case` | `admin`, `reassign` | `CASE`, `COMPANY` | `P2_SENSITIVE` | `critical` | — | Administer workflow cases. |
| `workflow.template.manage` | `workflow_definition` | `create`, `update`, `publish` | `COMPANY` | `P1_INTERNAL` | `high` | `workflow_admin` | Create, version and publish approval workflow templates and node rules. |
| `time.attendance.read` | `attendance_day` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read attendance days. |
| `time.punch.import` | `punch_record` | `import` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | — | Import raw device punches idempotently. |
| `time.attendance.correct.request` | `attendance_correction` | `request` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Request an attendance correction Case. |
| `time.calendar.read` | `legal_entity_calendar` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read legal entity calendars and holidays. |
| `time.shift.read` | `shift_template` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read shift templates. |
| `time.attendance.manage` | `attendance_day` | `create`, `update`, `correct` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | — | Manage and correct attendance. |
| `time.leave.read` | `leave_account` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read leave balances and records. |
| `time.leave.request` | `leave_request` | `create`, `withdraw` | `SELF` | `P0_PUBLIC` | `low` | — | Submit or withdraw own leave requests. |
| `time.leave.admin` | `leave_account` | `grant`, `adjust`, `admin` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Administer leave accounts and entries. |
| `time.leave.request.cancel` | `leave_request` | `cancel` | `SELF` | `P0_PUBLIC` | `low` | — | Cancel own leave request and release its reservation. |
| `time.leave.ledger.read` | `leave_ledger` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read append-only leave ledger entries and reconciliation summary. |
| `time.calendar.manage` | `legal_entity_calendar` | `create`, `update`, `publish` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_calendar` | Configure and publish statutory holidays, rest days and workday calendar versions. |
| `time.overtime.read` | `overtime_request` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read overtime requests, confirmations and time-off balances. |
| `time.overtime.request` | `overtime_request` | `create`, `withdraw` | `SELF`, `MANAGED` | `P0_PUBLIC` | `low` | — | Submit or withdraw own overtime requests. |
| `time.overtime.manage` | `overtime_request` | `create`, `update`, `approve` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_overtime` | Manage overtime policies, confirmations and time-off conversion. |
| `time.overtime.settle` | `overtime_settlement` | `settle`, `reverse` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_publish` | Settle overtime through pay or time-off and reverse an erroneous settlement. |
| `time.travel.read` | `travel_request` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read business-travel requests, changes and reports. |
| `time.travel.request` | `travel_request` | `create`, `update`, `cancel` | `SELF` | `P0_PUBLIC` | `low` | — | Create, change or cancel own business-travel requests. |
| `time.travel.manage` | `travel_request` | `create`, `update`, `approve` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_travel` | Manage travel policies, approvals and department travel statistics. |
| `time.travel.expense` | `travel_expense` | `create`, `update` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `time_travel` | Register travel expenses and receipts against a budget. |
| `payroll.run.read` | `payroll_run` | `read` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `high` | `payroll_prepare` | Read payroll run metadata and inputs. |
| `payroll.run.prepare` | `payroll_run` | `prepare` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_prepare` | Prepare payroll runs and inputs. |
| `payroll.run.review` | `payroll_run` | `review` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_review` | Review calculated payroll runs. |
| `payroll.run.approve` | `payroll_run` | `approve` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_approve` | Approve reviewed payroll runs. |
| `payroll.run.publish` | `payroll_run` | `publish` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_publish` | Publish approved payroll runs. |
| `payroll.payslip.read` | `payslip` | `read` | `SELF`, `PAYROLL_RUN`, `COMPANY` | `P3_RESTRICTED` | `high` | `payroll_prepare` | Read payslips; SELF resolves to the caller employment. |
| `payroll.payslip.read.self` | `payslip` | `read` | `SELF` | `P3_RESTRICTED` | `medium` | — | Read own payslips. |
| `payroll.export` | `payroll_run` | `export` | `PAYROLL_RUN`, `COMPANY` | `P3_RESTRICTED` | `critical` | `payroll_publish` | Export payroll data with an explicit export capability. |
| `payroll.run.close` | `payroll_run` | `close` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_publish` | Mark a published run paid and close it. |
| `payroll.run.correct` | `payroll_run` | `create`, `correct` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_approve` | Create correction runs referencing a published original. |
| `payroll.run.reopen` | `payroll_run` | `reopen` | `PAYROLL_RUN`, `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_approve` | Reopen a pre-publication run with dual approval. |
| `payroll.parameters.read` | `payroll_parameter` | `read` | `COMPANY` | `P2_SENSITIVE` | `high` | `payroll_parameters` | Read effective-dated statutory parameters and evidence. |
| `payroll.parameters.manage` | `payroll_parameter` | `create`, `approve` | `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_parameters` | Create approved statutory parameter versions with evidence. |
| `talent.recruitment.manage` | `recruitment` | `create`, `update`, `admin` | `ORG_UNIT`, `ORG_SUBTREE`, `CASE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Manage recruitment requisitions and candidates. |
| `talent.performance.manage` | `performance` | `create`, `update`, `admin` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Manage performance cycles and reviews. |
| `talent.training.manage` | `training` | `create`, `update`, `admin` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Manage training catalogues and attendance. |
| `recruitment.requisition.read` | `recruitment_requisition` | `read` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read recruitment requisitions. |
| `recruitment.board.read` | `recruitment_board` | `read` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read the live recruitment funnel board and recruitment statistics. |
| `recruitment.budget.read` | `recruitment_budget` | `read` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read recruitment budget, committed offer cost and headcount gaps. |
| `recruitment.requisition.manage` | `recruitment_requisition` | `create`, `approve` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Create and approve requisition cases. |
| `recruitment.requisition.export` | `recruitment_requisition` | `export` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | `recruitment_export` | Export requisitions, budgets and recruitment statistics. |
| `recruitment.candidate.read` | `candidate` | `read` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Read candidate pipeline records with minimized PII. |
| `recruitment.candidate.manage` | `candidate` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Create and update candidate records. |
| `recruitment.interview.manage` | `interview` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Schedule interviews and record feedback. |
| `recruitment.offer.manage` | `offer` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P3_RESTRICTED` | `high` | `recruitment_offer` | Draft and submit versioned offers. |
| `recruitment.offer.accept` | `offer` | `accept` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P3_RESTRICTED` | `high` | `recruitment_offer` | Accept an approved offer and start onboarding. |
| `performance.cycle.read` | `performance_cycle` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read performance cycles. |
| `performance.cycle.manage` | `performance_cycle` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Create performance cycles. |
| `performance.review.manage` | `performance_review` | `create`, `update` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Create performance reviews. |
| `performance.review.submit` | `performance_review` | `submit` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Submit performance reviews. |
| `performance.appeal.manage` | `performance_appeal` | `create` | `SELF`, `MANAGED`, `COMPANY` | `P2_SENSITIVE` | `medium` | — | Raise a performance appeal as a Workflow case. |
| `performance.calibration.manage` | `performance_calibration` | `create`, `update`, `publish` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `performance_calibration` | Run calibration sessions and publish calibrated performance ratings. |
| `performance.report.read` | `performance_cycle` | `read` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read performance distribution, trend and department reports. |
| `training.program.read` | `training_program` | `read` | `SELF`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read training programs. |
| `training.program.manage` | `training_program` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Manage training programs. |
| `training.session.manage` | `training_session` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Schedule training sessions with capacity. |
| `training.enrollment.manage` | `training_enrollment` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Enrol employees in training sessions. |
| `training.completion.manage` | `training_enrollment` | `complete` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Record training attendance and completion. |
| `training.report.read` | `training_enrollment` | `read` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read training attendance reports. |
| `engagement.notice.read` | `notice` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read engagement notices. |
| `engagement.notice.manage` | `notice` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Manage engagement notices. |
| `engagement.notice.publish` | `notice` | `publish`, `retract` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `notice_publish` | Schedule, publish, pin or retract an approved notice. |
| `engagement.notice.export` | `notice` | `export` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `notice_publish` | Export notice read rates and acknowledgement details. |
| `notification.read` | `notification` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read in-app notifications and delivery status. |
| `engagement.notification.read` | `notification` | `read` | `SELF` | `P1_INTERNAL` | `low` | — | Legacy route code for reading the caller own in-app notifications; same semantics as notification.read. |
| `notification.manage` | `notification_template` | `create`, `update`, `retry` | `COMPANY` | `P1_INTERNAL` | `high` | `notification_admin` | Manage notification templates and retry failed delivery attempts. |
| `reporting.dashboard.read` | `dashboard` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read role-scoped dashboards. |
| `reporting.export` | `report` | `export` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Export reports with a field whitelist and row cap. |
| `reporting.export.read` | `export_job` | `read` | `SELF`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Read export job history with hashes, field whitelists and audit trail. |
| `reporting.report.read` | `report` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `medium` | — | Read report definitions and execute authorized report views. |
| `reporting.report.create` | `report` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | `report_create` | Build and save custom report definitions from approved dimensions and metrics. |
| `reporting.subscription.manage` | `report_subscription` | `create`, `update`, `delete` | `SELF`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | `report_create` | Create, pause or remove report subscriptions owned by the caller or role. |
| `system.settings.manage` | `system_setting` | `create`, `update`, `delete` | `COMPANY` | `P2_SENSITIVE` | `critical` | `system_config` | Manage dictionaries, global parameters, file classifications and access policy. |
| `org.position.qualification.manage` | `position_qualification` | `create`, `update`, `delete` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | `org_position` | Version and retire position qualification requirements. |
| `org.position.grade.manage` | `position_grade` | `create`, `update` | `COMPANY` | `P1_INTERNAL` | `medium` | `org_position` | Maintain the job-grade catalogue used by positions and compensation bands. |
| `org.position.staff.read` | `position_assignment` | `read` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read current incumbents of a position within the caller anchor. |
| `lifecycle.secondment.manage` | `secondment` | `create`, `update`, `terminate` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `lifecycle_people` | Start, extend and end temporary secondment arrangements. |
| `people.document.read` | `document` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | List employee documents and their signature state. |
| `people.contract.template.manage` | `contract_template` | `create`, `update`, `publish` | `COMPANY` | `P1_INTERNAL` | `high` | `people_manage` | Version and publish employment-contract templates. |
| `people.contract.amend` | `contract` | `amend` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `people_manage` | Append an effective-dated contract amendment without rewriting history. |
| `people.contract.terminate` | `contract` | `terminate` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `critical` | `people_manage` | Terminate an employment contract with an effective date and reason. |
| `time.attendance.rule.read` | `attendance_rule` | `read` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read attendance rules and their effective versions. |
| `time.attendance.rule.manage` | `attendance_rule` | `create`, `update`, `publish` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_calendar` | Version and publish attendance rules such as grace periods and rounding. |
| `time.shift.schedule.read` | `shift_schedule` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read published shift schedules. |
| `time.shift.schedule.manage` | `shift_schedule` | `create`, `update`, `publish` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_calendar` | Create and publish shift schedules for an org unit. |
| `time.attendance.reopen` | `attendance_period` | `reopen` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_calendar` | Reopen a closed attendance period with an audited reason before recalculation. |
| `time.attendance.device.read` | `attendance_device` | `read` | `COMPANY` | `P1_INTERNAL` | `medium` | — | Read attendance device inventory and health status. |
| `time.overtime.confirm` | `overtime_request` | `confirm` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_overtime` | Confirm actual overtime minutes separately from the declared amount. |
| `time.timeoff.read` | `timeoff_balance` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read time-off-in-lieu balances derived from confirmed overtime settlements. |
| `time.travel.policy.read` | `travel_policy` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read travel policy versions and expense limits. |
| `time.travel.policy.manage` | `travel_policy` | `create`, `update`, `publish` | `COMPANY` | `P1_INTERNAL` | `high` | `time_travel` | Version and publish travel policies. |
| `time.travel.approve` | `travel_request` | `approve` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `high` | `time_travel` | Approve or reject a business-travel request. |
| `time.travel.expense.manage` | `travel_expense` | `create`, `update`, `submit` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `time_travel` | Register and submit travel expenses against an approved request and budget. |
| `payroll.compensation.read` | `employee_compensation` | `read` | `SELF`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Read effective-dated compensation plans; P3 amounts stay masked and audited. |
| `payroll.compensation.manage` | `employee_compensation` | `create`, `update` | `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_parameters` | Create effective-dated compensation versions in integer minor units. |
| `payroll.salary-adjustment.manage` | `salary_adjustment` | `create`, `approve` | `COMPANY` | `P2_SENSITIVE` | `critical` | `payroll_approve` | Record salary adjustments backed by an approved workflow case. |
| `performance.indicator.manage` | `performance_indicator` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Maintain performance indicator catalogues. |
| `performance.goal.manage` | `performance_goal` | `create`, `update` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `medium` | — | Set and update performance goals for a review. |
| `performance.feedback.manage` | `performance_feedback` | `create`, `update` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `medium` | — | Record continuous performance feedback. |
| `performance.result.publish` | `performance_cycle` | `publish` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | `performance_calibration` | Publish calibrated performance results and freeze the cycle. |
| `recruitment.candidate.import` | `candidate_import` | `create` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `high` | — | Import candidate rows with validation results and a rejected-row report. |
| `recruitment.evaluation.manage` | `candidate_evaluation` | `create`, `update` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `medium` | — | Record structured candidate evaluations. |
| `recruitment.communication.manage` | `candidate_communication` | `create` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `medium` | — | Log candidate communications for pipeline traceability. |
| `recruitment.talent-pool.manage` | `talent_pool_tag` | `create`, `delete` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `medium` | — | Tag and untag candidates in the talent pool. |
| `recruitment.candidate.convert` | `candidate` | `convert` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P2_SENSITIVE` | `critical` | `recruitment_offer` | Convert an accepted candidate into exactly one employment. |
| `training.plan.manage` | `training_plan` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Plan annual training curricula and budgets. |
| `training.course.manage` | `training_course` | `create`, `update` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Maintain course catalogues and materials. |
| `training.checkin.manage` | `training_checkin` | `create` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Record training attendance check-ins. |
| `training.exam.manage` | `training_exam` | `create`, `update`, `publish` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | — | Author, publish and grade training exams. |
| `training.feedback.manage` | `training_feedback` | `create` | `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Record training feedback and effectiveness scores. |
| `training.record.read` | `training_record` | `read` | `SELF`, `MANAGED`, `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `low` | — | Read consolidated training records and certificates. |
| `system.dictionary.manage` | `dictionary` | `create`, `update`, `delete` | `COMPANY` | `P1_INTERNAL` | `high` | `system_config` | Maintain tenant dictionaries through the Platform contract. |
| `system.parameter.manage` | `system_parameter` | `create`, `update` | `COMPANY` | `P2_SENSITIVE` | `high` | `system_config` | Maintain typed tenant parameters through the Platform contract. |
| `system.backup.manage` | `backup_record` | `create`, `read`, `restore` | `COMPANY` | `P2_SENSITIVE` | `critical` | `system_config` | Run backup and restore operations and read their evidence. |
| `notification.template.manage` | `notification_template` | `create`, `update` | `COMPANY` | `P1_INTERNAL` | `high` | `notification_admin` | Version notification templates and their variables. |
| `notification.delivery.manage` | `notification_delivery` | `retry`, `read` | `COMPANY` | `P1_INTERNAL` | `high` | `notification_admin` | Read delivery attempts and retry failed notifications. |
| `reporting.definition.manage` | `report_definition` | `create`, `update`, `publish` | `ORG_UNIT`, `ORG_SUBTREE`, `COMPANY` | `P1_INTERNAL` | `medium` | `report_create` | Create, version and publish report definitions from approved dimensions and metrics. |
| `platform.feature.manage` | `feature_flag` | `read`, `update` | `COMPANY` | `P1_INTERNAL` | `high` | `system_config` | Read and change platform feature flags for a legal entity. |
