import json
from pathlib import Path

path = Path('docs/audit/2026-11-26-v2-action-gaps.json')
doc = json.loads(path.read_text(encoding='utf-8'))

doc['capabilityGaps'] = [
    {
        'capability': 'people.employee.export',
        'pages': ['hr-employees', 'employee-detail', 'hr-employees-by-id-education', 'hr-employees-by-id-work-history', 'hr-employees-by-id-contacts', 'hr-employees-by-id-credentials', 'hr-employees-by-id-history', 'hr-employees-by-id-files', 'hr-employees-by-id-changes'],
        'missing': 'Employee export operation (GET /v1/employees/export) is absent from OpenAPI and routes.',
        'owner': 'Volume 3 Task 4',
    },
    {
        'capability': 'people.contract.export',
        'pages': ['hr-contracts', 'hr-contracts-by-id', 'hr-contracts-expiry'],
        'missing': 'Contract export operation (GET /v1/employments/contracts/export) is absent from OpenAPI and routes.',
        'owner': 'Volume 3 Task 8',
    },
    {
        'capability': 'org.unit.export',
        'pages': ['hr-departments', 'hr-departments-list'],
        'missing': 'Organization export operation (GET /v1/org-units/export) is absent from OpenAPI and routes.',
        'owner': 'Volume 3 Task 5',
    },
    {
        'capability': 'org.position.export',
        'pages': ['hr-positions'],
        'missing': 'Position export operation (GET /v1/positions/export) is absent from OpenAPI and routes.',
        'owner': 'Volume 3 Task 6',
    },
    {
        'capability': 'recruitment.requisition.export',
        'pages': ['recruitment-requests'],
        'missing': 'Requisition export operation (GET /v1/recruitment/requisitions/export) is absent from OpenAPI and routes.',
        'owner': 'Volume 3 Task 14',
    },
    {
        'capability': 'payroll.payslip.read',
        'pages': ['payroll-payslips'],
        'missing': 'Admin payslip read operation is absent; only payroll.payslip.read.self is routed for GET /v1/payroll/payslips.',
        'owner': 'Volume 3 Task 12',
    },
    {
        'capability': 'time.leave.admin',
        'pages': ['leave-types', 'leave-policies'],
        'missing': 'Leave type/policy administration writes are absent from OpenAPI and routes; GET endpoints require time.leave.read.',
        'owner': 'Volume 3 Task 10',
    },
    {
        'capability': 'time.overtime.manage',
        'pages': ['overtime-policies', 'overtime-confirm', 'overtime-timeoff', 'overtime-settlement'],
        'missing': 'Overtime policy administration writes are absent; reads use time.overtime.read and confirm uses time.overtime.confirm.',
        'owner': 'Volume 3 Task 11',
    },
    {
        'capability': 'time.travel.manage',
        'pages': ['travel-changes'],
        'missing': 'Travel request change/administration operation is absent; available writes use time.travel.request/approve.',
        'owner': 'Volume 3 Task 11',
    },
    {
        'capability': 'workflow.template.manage',
        'pages': ['workflow'],
        'missing': 'Workflow template governance write carries workflow.case.admin; no operation carries workflow.template.manage.',
        'owner': 'Volume 3 Task 17',
    },
    {
        'capability': 'notification.manage',
        'pages': ['notifications'],
        'missing': 'Notification administration operation is absent; templates use notification.template.manage and deliveries use notification.delivery.manage.',
        'owner': 'Volume 3 Task 16',
    },
    {
        'capability': 'iam.permission.read',
        'pages': ['system-permissions'],
        'missing': 'GET /v1/capabilities is guarded by iam.role.manage; no operation carries iam.permission.read.',
        'owner': 'Volume 3 Task 17',
    },
    {
        'capability': 'system.settings.manage',
        'pages': ['system-settings'],
        'missing': 'No operation carries system.settings.manage; dictionaries and parameters use system.dictionary.manage/system.parameter.manage.',
        'owner': 'Volume 3 Task 17',
    },
    {
        'capability': 'notification.read',
        'pages': ['notifications'],
        'missing': 'GET and mark-read notification routes carry engagement.notification.read; no operation carries notification.read.',
        'owner': 'Volume 3 Task 16',
    },
    {
        'capability': 'reporting.report.create',
        'pages': ['reports-builder'],
        'missing': 'Legacy code for saving a report definition is replaced by reporting.definition.manage on POST /v1/reporting/definitions.',
        'owner': 'Volume 3 Task 16',
    },
]

path.write_text(json.dumps(doc, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(f"capability gaps {len(doc['capabilityGaps'])}; write gaps {len(doc['gaps'])}")
