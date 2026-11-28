<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

/**
 * Code-owned permission resource catalog.
 *
 * Every entry is a stable page resource: the route name is the page_code, the
 * path is the SPA route, required_capability gates menu visibility, actions are
 * the separately grantable operation capabilities, and required_apis are the
 * canonical OpenAPI operations the page consumes. Contract-pending operations
 * are intentionally listed before their route exists; the Wave 1 report
 * tracks those gaps instead of hiding them.
 *
 * Do not read docs at runtime and do not construct this from the database.
 */
final class PermissionResourceCatalog
{
    /** @var list<array<string, mixed>>|null */
    private ?array $pages = null;

    /**
     * @return list<array{
     *     page_code: string,
     *     path: string,
     *     required_capability: string,
     *     actions: list<string>,
     *     menu_group: string,
     *     menu_label: string,
     *     order: int,
     *     icon: string,
     *     component_key: string,
     *     required_apis: list<string>,
     *     states: list<string>,
     *     negative_tests: list<string>,
     *     layer: string,
     *     wave: string
     * }>
     */
    public function pages(): array
    {
        return $this->pages ??= $this->build();
    }

    /**
     * @return list<string>
     */
    public function pageCodes(): array
    {
        return array_map(static fn (array $page): string => $page['page_code'], $this->pages());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $pageCode): ?array
    {
        foreach ($this->pages() as $page) {
            if ($page['page_code'] === $pageCode) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pagesForCapability(string $capability): array
    {
        return array_values(array_filter(
            $this->pages(),
            static fn (array $page): bool => $page['required_capability'] === $capability,
        ));
    }

    /**
     * @param  list<string>  $grantedCapabilities
     * @return list<array{key: string, label: string, order: int, icon: string, pages: list<array<string, mixed>>}>
     */
    public function menusForCapabilities(array $grantedCapabilities): array
    {
        $granted = array_fill_keys($grantedCapabilities, true);
        $menus = [];

        foreach ($this->pages() as $page) {
            $capability = $page['required_capability'];

            if ($capability !== 'public' && ! isset($granted[$capability])) {
                continue;
            }

            $key = $page['menu_group'];
            $menus[$key] ??= [
                'key' => $key,
                'label' => $key,
                'order' => (int) $page['order'],
                'icon' => (string) $page['icon'],
                'pages' => [],
            ];
            $menus[$key]['order'] = min((int) $menus[$key]['order'], (int) $page['order']);
            $menus[$key]['pages'][] = $page;
        }

        $rows = array_values($menus);
        usort($rows, static fn (array $left, array $right): int => [$left['order'], $left['key']] <=> [$right['order'], $right['key']]);

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function componentKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $page): string => $page['component_key'],
            $this->pages(),
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(): array
    {
        return [
            [
                'page_code' => 'login',
                'path' => '/login',
                'required_capability' => 'public',
                'actions' => [],
                'menu_group' => '工作台与安全',
                'menu_label' => '登录',
                'order' => 0,
                'icon' => 'Lock',
                'component_key' => 'base/LoginView',
                'required_apis' => ['POST /v1/auth/login'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'base',
                'wave' => 'base',
            ],
            [
                'page_code' => 'workspace',
                'path' => '/workspace',
                'required_capability' => 'iam.session.manage',
                'actions' => ['iam.session.manage'],
                'menu_group' => '工作台与安全',
                'menu_label' => '我的工作台',
                'order' => 0,
                'icon' => 'HomeFilled',
                'component_key' => 'base/WorkspaceView',
                'required_apis' => ['GET /v1/me/workspace'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'base',
                'wave' => 'base',
            ],
            [
                'page_code' => 'forbidden',
                'path' => '/forbidden',
                'required_capability' => 'public',
                'actions' => ['iam.session.manage'],
                'menu_group' => '工作台与安全',
                'menu_label' => '无权限',
                'order' => 0,
                'icon' => 'Lock',
                'component_key' => 'base/ForbiddenView',
                'required_apis' => ['GET /v1/me/permissions'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'base',
                'wave' => 'base',
            ],
            [
                'page_code' => 'not-found',
                'path' => '/not-found',
                'required_capability' => 'public',
                'actions' => ['iam.session.manage'],
                'menu_group' => '工作台与安全',
                'menu_label' => '页面不存在',
                'order' => 0,
                'icon' => 'QuestionFilled',
                'component_key' => 'base/NotFoundView',
                'required_apis' => ['GET /v1/me/permissions'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'base',
                'wave' => 'base',
            ],
            [
                'page_code' => 'dashboard',
                'path' => '/dashboard',
                'required_capability' => 'reporting.dashboard.read',
                'actions' => ['reporting.dashboard.read'],
                'menu_group' => '仪表盘',
                'menu_label' => '首页看板',
                'order' => 1,
                'icon' => 'Menu',
                'component_key' => 'dashboard/index',
                'required_apis' => ['GET /v1/reporting/dashboard'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'reports',
                'path' => '/reports',
                'required_capability' => 'reporting.export',
                'actions' => ['reporting.export', 'reporting.report.read'],
                'menu_group' => '报表中心',
                'menu_label' => '报表目录',
                'order' => 7,
                'icon' => 'Menu',
                'component_key' => 'reports/index',
                'required_apis' => ['GET /v1/reporting/definitions'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'hr-employees',
                'path' => '/hr/employees',
                'required_capability' => 'people.employee.read',
                'actions' => ['people.contract.manage', 'people.employee.manage', 'people.employee.read', 'people.employee.export'],
                'menu_group' => '员工档案',
                'menu_label' => '员工列表',
                'order' => 16,
                'icon' => 'Menu',
                'component_key' => 'hr/employees/index',
                // One write API owns exactly one page action. Archive/restore
                // live on the employee DETAIL page (ADR-0036); this list page
                // only creates employment records. Contract creation now lives
                // in the contract workspace drawer (ADR-0035).
                'required_apis' => ['GET /v1/employees', 'GET /v1/employees/export', 'POST /v1/employments', 'POST /v1/employments/{employmentId}/contracts'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'employee-detail',
                'path' => '/hr/employees/:id',
                'required_capability' => 'people.employee.read',
                'actions' => ['people.assignment.manage', 'people.document.download', 'people.employee.archive', 'people.employee.manage', 'people.employee.read', 'people.employee.restore'],
                'menu_group' => '员工档案',
                'menu_label' => '档案详情',
                'order' => 18,
                'icon' => 'Menu',
                'component_key' => 'hr/employees/by-id',
                'required_apis' => ['GET /v1/employees/{employment_id}', 'POST /v1/employments/{employment_id}/assignments', 'DELETE /v1/employees/{employment_id}', 'POST /v1/employees/{employment_id}/restore', 'GET /v1/documents/{document_id}/signed-download', 'POST /v1/employees/{employmentId}/change_requests/{id}/submit', 'DELETE /v1/employees/{employmentId}/education/{id}', 'DELETE /v1/employees/{employmentId}/experiences/{id}', 'DELETE /v1/employees/{employmentId}/contacts/{id}', 'DELETE /v1/employees/{employmentId}/certificates/{id}'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'hr-departments',
                'path' => '/hr/departments',
                'required_capability' => 'org.unit.read',
                'actions' => ['org.tree.read', 'org.unit.manage', 'org.unit.read'],
                'menu_group' => '部门管理',
                'menu_label' => '组织架构树',
                'order' => 27,
                'icon' => 'Menu',
                'component_key' => 'hr/departments/index',
                'required_apis' => ['GET /v1/org-units/tree', 'POST /v1/departments', 'PATCH /v1/departments/{id}', 'DELETE /v1/departments/{id}/members/{employmentId}'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'hr-positions',
                'path' => '/hr/positions',
                'required_capability' => 'org.position.read',
                'actions' => ['org.position.grade.manage', 'org.position.manage', 'org.position.qualification.manage', 'org.position.read'],
                'menu_group' => '岗位管理',
                'menu_label' => '岗位目录',
                'order' => 33,
                'icon' => 'Menu',
                'component_key' => 'hr/positions/index',
                // The create/edit drawer is an action on the catalogue page.
                'required_apis' => ['GET /v1/positions', 'POST /v1/positions', 'PATCH /v1/positions/{id}', 'POST /v1/positions/grades', 'POST /v1/positions/{id}/requirements'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'hr-movements',
                'path' => '/hr/movements',
                'required_capability' => 'lifecycle.case.read',
                'actions' => ['lifecycle.case.read', 'lifecycle.checklist.manage', 'lifecycle.offboard.manage', 'lifecycle.onboarding.manage', 'lifecycle.probation.manage', 'lifecycle.secondment.manage', 'lifecycle.terms.manage', 'lifecycle.transfer.manage', 'workflow.case.read'],
                'menu_group' => '员工异动',
                'menu_label' => '异动列表',
                'order' => 39,
                'icon' => 'Menu',
                'component_key' => 'hr/movements/index',
                'required_apis' => ['GET /v1/cases', 'PATCH /v1/onboarding-tasks/{id}', 'POST /v1/lifecycle/onboarding', 'POST /v1/lifecycle/probation-confirmations', 'POST /v1/lifecycle/transfers', 'POST /v1/lifecycle/salary-changes', 'POST /v1/lifecycle/offboardings', 'POST /v1/lifecycle/secondments'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'hr-movements-by-id',
                'path' => '/hr/movements/:id',
                'required_capability' => 'lifecycle.case.read',
                'actions' => ['lifecycle.case.read'],
                'menu_group' => '员工异动',
                'menu_label' => '异动审批详情',
                'order' => 46,
                'icon' => 'Menu',
                'component_key' => 'hr/movements/by-id',
                'required_apis' => ['GET /v1/lifecycle/cases/{caseId}'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'hr-contracts',
                'path' => '/hr/contracts',
                'required_capability' => 'people.contract.read',
                'actions' => ['people.contract.manage', 'people.contract.read'],
                'menu_group' => '劳动合同',
                'menu_label' => '合同台账',
                'order' => 48,
                'icon' => 'Menu',
                'component_key' => 'hr/contracts/index',
                // Contract create/renew actions are drawers on this workspace.
                'required_apis' => ['GET /v1/employments/{employmentId}/contracts', 'POST /v1/employments/{employmentId}/contracts'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'hr-contracts-by-id',
                'path' => '/hr/contracts/:id',
                'required_capability' => 'people.contract.read',
                'actions' => ['people.contract.read', 'people.document.download', 'people.contract.amend', 'people.contract.terminate', 'people.contract.manage'],
                'menu_group' => '劳动合同',
                'menu_label' => '合同详情',
                'order' => 50,
                'icon' => 'Menu',
                'component_key' => 'hr/contracts/by-id',
                'required_apis' => ['GET /v1/employments/{employmentId}/contracts/{id}', 'GET /v1/documents/{document_id}/signed-download', 'POST /v1/employment-contracts/{id}/amendments', 'POST /v1/employment-contracts/{id}/terminate', 'POST /v1/employment-contracts/{id}/activate'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 2',
            ],
            [
                'page_code' => 'attendance',
                'path' => '/attendance',
                'required_capability' => 'time.attendance.read',
                'actions' => ['time.attendance.correct.request', 'time.attendance.export', 'time.attendance.manage', 'time.attendance.read', 'time.attendance.reopen', 'time.attendance.rule.manage', 'time.punch.read', 'time.shift.manage', 'time.shift.schedule.manage'],
                'menu_group' => '考勤打卡',
                'menu_label' => '我的打卡',
                'order' => 55,
                'icon' => 'Menu',
                'component_key' => 'attendance/index',
                'required_apis' => ['GET /v1/time/attendance', 'GET /v1/time/attendance/records', 'GET /v1/time/punches', 'GET /v1/time/attendance/records/export', 'POST /v1/time/attendance/corrections', 'POST /v1/time/shifts', 'PATCH /v1/time/shifts/{id}', 'POST /v1/time/shifts/{id}/disable', 'POST /v1/time/attendance/rules', 'POST /v1/time/shift-schedules', 'POST /v1/time/attendance/closings', 'POST /v1/time/attendance/closings/{id}/reopen', 'POST /v1/time/attendance/recalculate'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 3',
            ],
            [
                'page_code' => 'leave',
                'path' => '/leave',
                'required_capability' => 'time.leave.read',
                'actions' => ['time.leave.admin', 'time.leave.policy.manage', 'time.leave.read', 'time.leave.request', 'time.leave.request.cancel', 'time.leave.request.on-behalf', 'time.leave.type.manage'],
                'menu_group' => '请假管理',
                'menu_label' => '请假申请',
                'order' => 65,
                'icon' => 'Menu',
                'component_key' => 'leave/index',
                'required_apis' => ['GET /v1/time/leave/balances', 'GET /v1/time/leave/types', 'POST /v1/time/leave/types', 'PATCH /v1/time/leave/types/{id}', 'GET /v1/time/leave/policies', 'POST /v1/time/leave/policies', 'POST /v1/time/leave/policies/{id}/retire', 'POST /v1/time/leave/requests', 'POST /v1/time/leave/requests/on-behalf', 'POST /v1/time/leave/requests/{caseId}/cancel', 'POST /v1/time/leave/requests/{caseId}/revoke', 'GET /v1/time/leave/reports/summary'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 3',
            ],
            [
                'page_code' => 'overtime',
                'path' => '/overtime',
                'required_capability' => 'time.overtime.read',
                'actions' => ['time.overtime.confirm', 'time.overtime.manage', 'time.overtime.read', 'time.overtime.request', 'time.overtime.settle', 'time.timeoff.read'],
                'menu_group' => '加班管理',
                'menu_label' => '加班申请',
                'order' => 73,
                'icon' => 'Menu',
                'component_key' => 'overtime/index',
                'required_apis' => ['GET /v1/time/overtime/policies', 'POST /v1/time/overtime/policies', 'POST /v1/time/overtime/policies/{id}/retire', 'GET /v1/time/overtime/requests', 'POST /v1/time/overtime/requests', 'POST /v1/time/overtime/requests/{id}/confirm', 'GET /v1/time/overtime/timeoff-balances', 'GET /v1/time/overtime/settlements', 'POST /v1/overtime/records/{recordId}/settlements'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 3',
            ],
            [
                'page_code' => 'travel',
                'path' => '/travel',
                'required_capability' => 'time.travel.read',
                'actions' => ['time.travel.approve', 'time.travel.expense', 'time.travel.expense.manage', 'time.travel.policy.manage', 'time.travel.policy.read', 'time.travel.read', 'time.travel.request'],
                'menu_group' => '出差管理',
                'menu_label' => '出差申请',
                'order' => 79,
                'icon' => 'Menu',
                'component_key' => 'travel/index',
                'required_apis' => ['GET /v1/travel/requests', 'POST /v1/travel/requests', 'POST /v1/travel/requests/{id}/change', 'POST /v1/travel/requests/{id}/approve', 'POST /v1/travel/requests/{id}/cancel', 'GET /v1/travel/policies', 'POST /v1/travel/policies', 'GET /v1/travel/expenses', 'POST /v1/travel/expenses', 'GET /v1/travel/reports/summary'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 3',
            ],
            [
                'page_code' => 'payroll',
                'path' => '/payroll',
                'required_capability' => 'payroll.run.read',
                'actions' => ['payroll.compensation.manage', 'payroll.export', 'payroll.payslip.read.self', 'payroll.report.read', 'payroll.run.prepare', 'payroll.run.publish', 'payroll.run.read', 'payroll.run.review'],
                'menu_group' => '薪资管理',
                'menu_label' => '薪资概览',
                'order' => 86,
                'icon' => 'Menu',
                'component_key' => 'payroll/index',
                'required_apis' => ['GET /v1/payroll/runs', 'POST /v1/payroll/runs', 'POST /v1/payroll/runs/{id}/review', 'POST /v1/payroll/runs/{id}/publish', 'GET /v1/payroll/runs/{id}/exports/bank', 'POST /v1/payroll/items/{id}/versions', 'POST /v1/payroll/items/{id}/retire', 'GET /v1/payroll/reports/summary', 'GET /v1/payroll/payslips', 'POST /v1/payroll/employee-plans', 'POST /v1/payroll/items', 'POST /v1/payroll/schemes'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 3',
            ],
            [
                'page_code' => 'performance',
                'path' => '/performance',
                'required_capability' => 'performance.cycle.read',
                'actions' => ['performance.appeal.manage', 'performance.cycle.manage', 'performance.cycle.read', 'performance.result.publish'],
                'menu_group' => '绩效考核',
                'menu_label' => '绩效周期',
                'order' => 98,
                'icon' => 'Menu',
                'component_key' => 'performance/index',
                'required_apis' => ['GET /v1/performance/cycles', 'POST /v1/performance/cycles', 'POST /v1/performance/cycles/{id}/publish', 'POST /v1/performance/reviews/{id}/appeals', 'GET /v1/performance/bonus-mappings', 'POST /v1/performance/bonus-mappings', 'POST /v1/performance/bonus-mappings/{id}/approve'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 4',
            ],
            [
                'page_code' => 'recruitment-requests',
                'path' => '/recruitment/requests',
                'required_capability' => 'recruitment.requisition.read',
                'actions' => ['recruitment.requisition.manage', 'recruitment.requisition.read'],
                'menu_group' => '招聘需求',
                'menu_label' => '招聘需求列表',
                'order' => 108,
                'icon' => 'Menu',
                'component_key' => 'recruitment/requests/index',
                // The create drawer is an action on the requisition workspace.
                'required_apis' => ['GET /v1/recruitment/requisitions', 'POST /v1/recruitment/requisitions', 'PATCH /v1/recruitment/requisitions/{id}', 'POST /v1/recruitment/requisitions/{id}/close', 'GET /v1/recruitment/board', 'GET /v1/recruitment/budget', 'GET /v1/recruitment/reports'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 4',
            ],
            [
                'page_code' => 'recruitment-candidates',
                'path' => '/recruitment/candidates',
                'required_capability' => 'recruitment.candidate.read',
                'actions' => ['recruitment.candidate.convert', 'recruitment.candidate.import', 'recruitment.candidate.manage', 'recruitment.candidate.read', 'recruitment.offer.accept', 'recruitment.offer.manage', 'recruitment.talent-pool.manage'],
                'menu_group' => '候选人',
                'menu_label' => '候选人列表',
                'order' => 115,
                'icon' => 'Menu',
                'component_key' => 'recruitment/candidates/index',
                'required_apis' => ['GET /v1/recruitment/candidates', 'PATCH /v1/recruitment/candidates/{id}/stage', 'POST /v1/recruitment/candidates/imports', 'POST /v1/recruitment/offers', 'POST /v1/recruitment/offers/{id}/accept', 'POST /v1/recruitment/candidates/{id}/convert', 'POST /v1/recruitment/candidates/{id}/talent-pool', 'POST /v1/recruitment/candidates/{id}/applications'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 4',
            ],
            [
                'page_code' => 'recruitment-candidates-by-id',
                'path' => '/recruitment/candidates/:id',
                'required_capability' => 'recruitment.candidate.read',
                'actions' => ['recruitment.candidate.read'],
                'menu_group' => '候选人',
                'menu_label' => '候选人档案',
                'order' => 116,
                'icon' => 'Menu',
                'component_key' => 'recruitment/candidates/by-id',
                'required_apis' => ['GET /v1/recruitment/candidates'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 4',
            ],
            [
                'page_code' => 'training',
                'path' => '/training',
                'required_capability' => 'training.program.read',
                'actions' => ['training.course.manage', 'training.enrollment.manage', 'training.program.manage', 'training.program.read', 'training.session.manage'],
                'menu_group' => '培训管理',
                'menu_label' => '培训计划',
                'order' => 125,
                'icon' => 'Menu',
                'component_key' => 'training/index',
                'required_apis' => ['GET /v1/training/programs', 'POST /v1/training/programs', 'PATCH /v1/training/programs/{id}', 'PATCH /v1/training/courses/{id}', 'PATCH /v1/training/sessions/{id}', 'POST /v1/training/enrollments/{id}/cancel'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 4',
            ],
            [
                'page_code' => 'notices',
                'path' => '/notices',
                'required_capability' => 'engagement.notice.read',
                'actions' => ['engagement.notice.manage', 'engagement.notice.publish', 'engagement.notice.read'],
                'menu_group' => '公告通知',
                'menu_label' => '公告列表',
                'order' => 134,
                'icon' => 'Menu',
                'component_key' => 'notices/index',
                // Composer and archive/restore are actions on this workspace.
                'required_apis' => ['GET /v1/engagement/notices', 'POST /v1/engagement/notices', 'POST /v1/engagement/notices/audience-preview', 'POST /v1/engagement/notices/{notice}/submit', 'POST /v1/engagement/notices/{notice}/decision', 'POST /v1/engagement/notices/{notice}/publish', 'POST /v1/engagement/notices/{notice}/archive', 'POST /v1/engagement/notices/{notice}/restore', 'GET /v1/engagement/notices/{notice}/reads', 'POST /v1/engagement/notice-templates', 'POST /v1/engagement/notices/{notice}/remind'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'account',
                'path' => '/account',
                'required_capability' => 'iam.session.manage',
                'actions' => ['iam.session.manage'],
                'menu_group' => '系统管理',
                'menu_label' => '登录与账号安全',
                'order' => 101,
                'icon' => 'Menu',
                'component_key' => 'account',
                'required_apis' => ['GET /v1/me', 'GET /v1/auth/mfa/status', 'GET /v1/auth/sessions', 'POST /v1/auth/password', 'POST /v1/auth/logout-all', 'DELETE /v1/auth/sessions/{id}', 'POST /v1/auth/mfa/totp/enroll', 'POST /v1/auth/mfa/totp/confirm', 'POST /v1/auth/mfa/totp/disable'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'system-users',
                'path' => '/system/users',
                'required_capability' => 'iam.user.read',
                'actions' => ['iam.binding.manage', 'iam.role.manage', 'iam.user.manage', 'iam.user.read'],
                'menu_group' => '系统管理',
                'menu_label' => '用户管理',
                'order' => 102,
                'icon' => 'Menu',
                'component_key' => 'system/system-users',
                'required_apis' => ['GET /v1/users', 'POST /v1/users', 'PATCH /v1/users/{id}', 'GET /v1/users/{id}/bindings', 'GET /v1/users/{id}/permissions-preview', 'POST /v1/users/{id}/roles'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'system-roles',
                'path' => '/system/roles',
                'required_capability' => 'iam.role.manage',
                'actions' => ['iam.permission.read', 'iam.role.manage'],
                'menu_group' => '系统管理',
                'menu_label' => '角色管理',
                'order' => 103,
                'icon' => 'Menu',
                'component_key' => 'system/system-roles',
                'required_apis' => ['GET /v1/roles', 'GET /v1/roles/{id}/pages', 'GET /v1/capabilities', 'POST /v1/roles', 'POST /v1/roles/{id}/copy', 'PATCH /v1/roles/{id}', 'DELETE /v1/roles/{id}', 'PUT /v1/roles/{id}/authorization'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'system-pages',
                'path' => '/system/pages',
                'required_capability' => 'iam.permission.read',
                'actions' => ['iam.menu.manage', 'iam.permission.read', 'iam.role.manage'],
                'menu_group' => '系统管理',
                'menu_label' => '页面目录',
                'order' => 104,
                'icon' => 'Menu',
                'component_key' => 'system/system-pages',
                'required_apis' => ['GET /v1/pages/catalog', 'GET /v1/system/menus', 'POST /v1/system/menus', 'PATCH /v1/system/menus/{page_code}', 'DELETE /v1/system/menus/{page_code}', 'GET /v1/capabilities'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'workflow',
                'path' => '/workflow',
                'required_capability' => 'workflow.case.read',
                'actions' => ['workflow.case.admin', 'workflow.case.create', 'workflow.case.decide', 'workflow.case.read'],
                'menu_group' => '系统管理',
                'menu_label' => '审批流程中心',
                'order' => 108,
                'icon' => 'Menu',
                'component_key' => 'workflow',
                'required_apis' => ['GET /v1/cases', 'GET /v1/tasks/my', 'GET /v1/workflow/templates', 'POST /v1/workflow/templates', 'POST /v1/cases/{caseId}/decisions', 'POST /v1/cases/{id}/withdraw', 'POST /v1/effects/{id}/retry'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'benefits',
                'path' => '/benefits',
                'required_capability' => 'benefit.read',
                'actions' => ['benefit.manage', 'benefit.read'],
                'menu_group' => '系统管理',
                'menu_label' => '社保公积金与福利',
                'order' => 110,
                'icon' => 'Menu',
                'component_key' => 'benefits',
                'required_apis' => ['GET /v1/benefits/plans', 'GET /v1/benefits/enrollments', 'GET /v1/benefits/transactions', 'POST /v1/benefits/plans', 'POST /v1/benefits/enrollments', 'POST /v1/benefits/transactions', 'POST /v1/benefits/transactions/{id}/reverse'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'system-settings',
                'path' => '/system/settings',
                'required_capability' => 'system.settings.manage',
                'actions' => ['platform.feature.manage', 'system.dictionary.manage', 'system.parameter.manage', 'system.settings.manage', 'time.calendar.manage', 'time.calendar.read'],
                'menu_group' => '系统管理',
                'menu_label' => '公司设置',
                'order' => 112,
                'icon' => 'Menu',
                'component_key' => 'system/system-settings',
                'required_apis' => ['GET /v1/system/company-profile', 'PATCH /v1/system/company-profile', 'GET /v1/platform/features', 'PUT /v1/platform/features/{flag}', 'GET /v1/system/dictionaries', 'PATCH /v1/system/dictionaries/{id}', 'GET /v1/system/parameters', 'PUT /v1/system/parameters', 'GET /v1/time/calendars', 'POST /v1/system/calendars/publish'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'notifications',
                'path' => '/notifications',
                'required_capability' => 'notification.read',
                'actions' => ['notification.delivery.manage', 'notification.read'],
                'menu_group' => '系统管理',
                'menu_label' => '消息与通知中心',
                'order' => 113,
                'icon' => 'Menu',
                'component_key' => 'notifications',
                'required_apis' => ['GET /v1/engagement/notifications', 'POST /v1/notifications/deliveries/{id}/retry'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'system-audit',
                'path' => '/system/audit',
                'required_capability' => 'iam.audit.read',
                'actions' => ['iam.audit.read'],
                'menu_group' => '系统管理',
                'menu_label' => '日志审计与运维',
                'order' => 114,
                'icon' => 'Menu',
                'component_key' => 'system/system-audit',
                'required_apis' => ['GET /v1/audit/events', 'POST /v1/audit/events/export', 'GET /v1/health/ready'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
            [
                'page_code' => 'payroll-run-detail',
                'path' => '/payroll/runs/:id',
                'required_capability' => 'payroll.run.read',
                'actions' => ['payroll.run.read', 'payroll.run.prepare', 'payroll.run.review', 'payroll.run.approve', 'payroll.run.publish', 'payroll.run.close', 'payroll.run.reopen', 'payroll.run.correct'],
                'menu_group' => '薪资管理',
                'menu_label' => '工资批次详情',
                'order' => 0,
                'icon' => 'Menu',
                'component_key' => 'payroll/runs/by-id',
                'required_apis' => ['GET /v1/payroll/runs/{id}', 'GET /v1/payroll/runs/{id}/employees', 'POST /v1/payroll/runs/{id}/lock-inputs', 'POST /v1/payroll/runs/{id}/calculate', 'POST /v1/payroll/runs/{id}/review', 'POST /v1/payroll/runs/{id}/approve', 'POST /v1/payroll/runs/{id}/publish', 'POST /v1/payroll/runs/{id}/cancel', 'POST /v1/payroll/runs/{id}/reopen', 'POST /v1/payroll/runs/{id}/corrections', 'POST /v1/payroll/runs/{id}/mark-paid', 'POST /v1/payroll/runs/{id}/close', 'POST /v1/payroll/runs/{id}/members'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 3',
            ],
            [
                'page_code' => 'performance-cycle-detail',
                'path' => '/performance/cycles/:id',
                'required_capability' => 'performance.cycle.read',
                'actions' => ['performance.cycle.read'],
                'menu_group' => '绩效考核',
                'menu_label' => '绩效周期详情',
                'order' => 0,
                'icon' => 'Menu',
                'component_key' => 'performance/cycles/by-id',
                'required_apis' => ['GET /v1/performance/cycles'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'business',
                'wave' => 'Wave 4',
            ],
            [
                'page_code' => 'workflow-case-detail',
                'path' => '/workflow/cases/:id',
                'required_capability' => 'workflow.case.read',
                'actions' => ['workflow.case.read'],
                'menu_group' => '系统管理',
                'menu_label' => '审批案例详情',
                'order' => 0,
                'icon' => 'Menu',
                'component_key' => 'workflow/cases/by-id',
                'required_apis' => ['GET /v1/cases/{id}', 'GET /v1/cases/{id}/timeline'],
                'states' => ['loading', 'empty', 'error', 'forbidden', 'partial'],
                'negative_tests' => ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
                'layer' => 'platform',
                'wave' => 'Wave 5',
            ],
        ];
    }
}
