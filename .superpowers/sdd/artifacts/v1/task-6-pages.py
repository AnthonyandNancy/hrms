from pathlib import Path
import re

p = Path('backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php')
s = p.read_text(encoding='utf-8')


def set_field(code: str, field: str, items: list[str]) -> None:
    global s
    pat = re.compile(
        r"(                'page_code' => '" + re.escape(code) + r"',\n"
        r"(?:                '[^\n]*\n)*?                '" + re.escape(field) + r"' => )\[(.*?)\](,\n)",
        re.S,
    )
    value = '[' + ', '.join("'" + item + "'" for item in items) + ']'
    s, n = pat.subn(lambda m: m.group(1) + value + m.group(3), s, count=1)

    if n != 1:
        raise SystemExit(f'{code}.{field}: expected one replacement, got {n}')


# Correct stale OpenAPI operation keys to the real operation keys.
for old, new in [
    ('/v1/employees/{employment_id}/education', '/v1/employees/{employmentId}/education'),
    ('/v1/employees/{employment_id}/experiences', '/v1/employees/{employmentId}/experiences'),
    ('/v1/employees/{employment_id}/contacts', '/v1/employees/{employmentId}/contacts'),
    ('/v1/employees/{employment_id}/certificates', '/v1/employees/{employmentId}/certificates'),
    ('/v1/employees/{employment_id}/change_requests', '/v1/employees/{employmentId}/change_requests'),
    ('/v1/employees/{employment_id}/imports', '/v1/employees/{employmentId}/imports'),
    ('/v1/employments/{employment_id}/contracts/{id}', '/v1/employments/{employmentId}/contracts/{id}'),
    ('/v1/employments/{employment_id}/contracts', '/v1/employments/{employmentId}/contracts'),
    ('/v1/overtime/requests', '/v1/time/overtime/requests'),
    ('/v1/overtime/policies', '/v1/time/overtime/policies'),
]:
    s = s.replace(old, new)

# Employee list / detail advertise real archive + restore; export remains a Volume 3 gap.
set_field('hr-employees', 'actions', [
    'people.assignment.manage', 'people.contract.manage', 'people.contract.read',
    'people.document.download', 'people.employee.archive', 'people.employee.manage',
    'people.employee.read', 'people.employee.restore',
])
set_field('hr-employees', 'required_apis', [
    'GET /v1/employees', 'POST /v1/employments',
    'DELETE /v1/employees/{employment_id}', 'POST /v1/employees/{employment_id}/restore',
])
set_field('employee-detail', 'actions', [
    'people.assignment.manage', 'people.contract.manage', 'people.contract.read',
    'people.document.download', 'people.employee.archive', 'people.employee.manage',
    'people.employee.read', 'people.employee.restore',
])
set_field('employee-detail', 'required_apis', [
    'GET /v1/employees/{employment_id}',
    'POST /v1/employments/{employment_id}/assignments',
    'DELETE /v1/employees/{employment_id}', 'POST /v1/employees/{employment_id}/restore',
])
for code in [
    'hr-employees-by-id-education', 'hr-employees-by-id-work-history',
    'hr-employees-by-id-contacts', 'hr-employees-by-id-credentials',
    'hr-employees-by-id-history', 'hr-employees-by-id-files',
    'hr-employees-by-id-changes',
]:
    set_field(code, 'actions', [
        'people.assignment.manage', 'people.contract.manage', 'people.contract.read',
        'people.document.download', 'people.employee.manage', 'people.employee.read',
    ])

# Action codes with no backend action API are documented gaps, not catalog data.
unbacked = [
    'notification.manage', 'org.position.export', 'org.unit.export', 'payroll.payslip.read',
    'people.contract.export', 'people.employee.export', 'recruitment.requisition.export',
    'reporting.report.create', 'time.leave.admin', 'time.overtime.manage',
    'time.travel.manage', 'workflow.template.manage',
]
pat = re.compile(r"(                'actions' => )\[(.*?)\](,\n)", re.S)


def clean(m: re.Match[str]) -> str:
    items = re.findall(r"'([^']+)'", m.group(2))
    items = [item for item in items if item not in unbacked]
    return m.group(1) + '[' + ', '.join("'" + item + "'" for item in items) + ']' + m.group(3)


s = pat.sub(clean, s)

# IAM workspaces expose the write operations they actually host.
set_field('system-users', 'actions', ['iam.role.manage', 'iam.user.manage', 'iam.user.read'])
set_field('system-users', 'required_apis', [
    'GET /v1/users', 'POST /v1/users', 'PATCH /v1/users/{id}',
    'GET /v1/users/{id}/bindings', 'GET /v1/users/{id}/permissions-preview',
    'POST /v1/users/{id}/roles',
])
set_field('system-roles', 'actions', ['iam.field.manage', 'iam.role.manage', 'iam.scope.manage'])
set_field('system-roles', 'required_apis', [
    'GET /v1/roles', 'GET /v1/capabilities', 'POST /v1/roles',
    'PUT /v1/roles/{id}/pages', 'PUT /v1/roles/{id}/capabilities',
    'PUT /v1/system/data-scopes/{id}', 'PUT /v1/system/field-policies/{field_key}',
])

# Reporting: the builder saves definitions; subscriptions have a real POST.
set_field('reports-builder', 'actions', ['reporting.definition.manage', 'reporting.export', 'reporting.report.read'])
set_field('reports-builder', 'required_apis', ['POST /v1/reporting/run', 'POST /v1/reporting/definitions'])
set_field('reports-subscriptions', 'required_apis', ['GET /v1/reporting/definitions', 'POST /v1/reporting/subscriptions'])
set_field('reports-payroll', 'required_apis', ['GET /v1/reporting/definitions', 'GET /v1/payroll/runs/{id}/exports/bank'])

# Benefits has a real plan-version write.
set_field('benefits', 'required_apis', [
    'GET /v1/benefits/plans', 'GET /v1/benefits/enrollments',
    'GET /v1/benefits/transactions', 'POST /v1/benefits/plans',
])

# Workflow and checklist actions have real operations.
set_field('hr-movements', 'required_apis', ['GET /v1/cases', 'PATCH /v1/onboarding-tasks/{id}'])
set_field('workflow', 'actions', ['workflow.case.admin', 'workflow.case.decide', 'workflow.case.read'])
set_field('workflow', 'required_apis', [
    'GET /v1/cases', 'GET /v1/tasks/my', 'GET /v1/workflow/templates',
    'POST /v1/workflow/templates',
])
set_field('hr-contracts-renewals', 'actions', ['lifecycle.contract.manage', 'people.contract.manage', 'people.contract.read'])
set_field('hr-contracts-changes', 'actions', ['lifecycle.contract.manage', 'people.contract.manage', 'people.contract.read'])

# Staff lifecycle pages should expose their real create-case API.
set_field('hr-movements-onboarding', 'required_apis', ['POST /v1/lifecycle/onboarding'])
set_field('hr-movements-probation', 'required_apis', ['POST /v1/lifecycle/probation-confirmations'])
set_field('hr-movements-transfer', 'required_apis', ['POST /v1/lifecycle/transfers'])
set_field('hr-movements-salary-change', 'required_apis', ['POST /v1/lifecycle/salary-changes'])
set_field('hr-movements-secondment', 'required_apis', ['POST /v1/lifecycle/transfers'])
set_field('hr-movements-offboarding', 'required_apis', ['POST /v1/lifecycle/offboardings'])

# Attendance, leave, overtime, travel, training.
set_field('attendance', 'required_apis', ['GET /v1/time/attendance', 'POST /v1/time/attendance/recalculate'])
set_field('leave', 'required_apis', ['GET /v1/time/leave/balances', 'POST /v1/time/leave/requests'])
set_field('overtime', 'required_apis', ['GET /v1/time/overtime/requests', 'POST /v1/time/overtime/requests'])
set_field('overtime-requests', 'required_apis', ['GET /v1/time/overtime/requests', 'POST /v1/time/overtime/requests'])
set_field('overtime-policies', 'required_apis', ['GET /v1/time/overtime/policies', 'POST /v1/time/overtime/requests'])
set_field('overtime-confirm', 'actions', ['time.overtime.confirm', 'time.overtime.read', 'time.overtime.request'])
set_field('overtime-confirm', 'required_apis', ['GET /v1/time/overtime/requests', 'POST /v1/time/overtime/requests/{id}/confirm'])
set_field('overtime-timeoff', 'required_apis', ['GET /v1/time/overtime/timeoff-balances', 'POST /v1/time/overtime/requests'])
set_field('travel', 'required_apis', ['GET /v1/travel/requests', 'POST /v1/travel/requests'])
set_field('travel-requests', 'required_apis', ['GET /v1/travel/requests', 'POST /v1/travel/requests'])
set_field('travel-changes', 'required_apis', ['GET /v1/travel/requests', 'POST /v1/travel/requests/{id}/cancel'])
set_field('travel-reports', 'required_apis', ['GET /v1/travel/requests', 'POST /v1/travel/requests'])
set_field('training', 'required_apis', ['GET /v1/training/programs', 'POST /v1/training/programs'])
set_field('training-courses', 'required_apis', ['GET /v1/training/programs', 'POST /v1/training/courses'])

# Payroll: every declared action has a real read/write endpoint on the page.
set_field('payroll', 'actions', [
    'payroll.export', 'payroll.run.prepare', 'payroll.run.publish',
    'payroll.run.read', 'payroll.run.review',
])
set_field('payroll', 'required_apis', [
    'GET /v1/payroll/runs', 'POST /v1/payroll/runs',
    'POST /v1/payroll/runs/{id}/review', 'POST /v1/payroll/runs/{id}/publish',
    'GET /v1/payroll/runs/{id}/exports/bank',
])
set_field('payroll-components', 'required_apis', ['GET /v1/payroll/items', 'POST /v1/payroll/items'])
set_field('payroll-plans', 'required_apis', ['GET /v1/payroll/schemes', 'POST /v1/payroll/schemes'])
set_field('payroll-employee-plans', 'actions', ['payroll.compensation.manage', 'payroll.run.read'])
set_field('payroll-employee-plans', 'required_apis', ['GET /v1/payroll/runs', 'POST /v1/payroll/employee-plans'])
set_field('payroll-statutory', 'required_apis', ['GET /v1/payroll/statutory-parameters', 'POST /v1/payroll/statutory-parameters'])
set_field('payroll-inputs', 'required_apis', ['GET /v1/payroll/runs', 'POST /v1/payroll/runs'])
set_field('payroll-batches', 'required_apis', ['GET /v1/payroll/runs', 'POST /v1/payroll/runs'])
set_field('payroll-reviews', 'required_apis', ['GET /v1/payroll/runs', 'POST /v1/payroll/runs/{id}/review', 'POST /v1/payroll/runs/{id}/approve'])
set_field('payroll-disbursement', 'required_apis', ['GET /v1/payroll/runs', 'POST /v1/payroll/runs/{id}/publish', 'POST /v1/payroll/runs/{id}/close'])
set_field('payroll-payslips', 'required_apis', ['GET /v1/payroll/runs', 'GET /v1/payroll/runs/{id}/exports/bank'])
set_field('payroll-adjustments', 'required_apis', ['GET /v1/payroll/runs', 'POST /v1/payroll/runs/{id}/corrections'])
set_field('payroll-reports', 'required_apis', ['GET /v1/payroll/runs/{id}/reconciliation', 'GET /v1/payroll/runs/{id}/exports/tax'])

# Performance.
set_field('performance-templates', 'required_apis', ['GET /v1/performance/cycles', 'POST /v1/performance/templates'])
set_field('performance-indicators', 'required_apis', ['GET /v1/performance/cycles', 'POST /v1/performance/indicators'])
set_field('performance-goals', 'required_apis', ['GET /v1/performance/cycles', 'POST /v1/performance/goals'])
set_field('performance-results', 'required_apis', ['GET /v1/performance/cycles', 'POST /v1/performance/cycles/{id}/publish'])
set_field('performance-calibration', 'required_apis', ['GET /v1/performance/cycles', 'POST /v1/performance/reviews/{id}/calibration'])
set_field('performance-reviews', 'required_apis', [
    'GET /v1/performance/cycles', 'POST /v1/performance/reviews',
    'POST /v1/performance/reviews/{id}/submit',
])
set_field('performance-feedback', 'required_apis', ['GET /v1/performance/cycles', 'POST /v1/performance/feedback'])
set_field('performance-reports', 'required_apis', ['GET /v1/performance/reports'])

# Calendar and system-settings pages must map to operations that exist.
set_field('system-calendars', 'required_apis', [
    'GET /v1/time/calendars/{legal_entity_id}',
    'POST /v1/system/calendars/{legal_entity_id}/publish',
])
set_field('system-settings', 'required_apis', ['GET /v1/system/dictionaries', 'GET /v1/system/parameters'])

# Notifications page: page-action capability must match the hosted write API.
set_field('notifications', 'actions', ['notification.delivery.manage', 'notification.read'])
set_field('notifications', 'required_apis', ['GET /v1/engagement/notifications', 'POST /v1/notifications/deliveries/{id}/retry'])

p.write_text(s, encoding='utf-8')
print('rebuilt page catalog')
