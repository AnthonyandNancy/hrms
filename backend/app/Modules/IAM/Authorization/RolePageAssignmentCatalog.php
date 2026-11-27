<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;

/**
 * Code-owned role-to-page assignment matrix.
 *
 * A role assignment is a declaration for the visual role editor, not a second
 * authorization engine: the API still checks capabilities on every request.
 * Parent menu groups are only navigation containers; each page is listed by
 * its stable page_code so granting one page never implies another.
 */
final class RolePageAssignmentCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $assignments = null;

    /**
     * @return array<string, array{
     *     role_code: string,
     *     name: string,
     *     default_anchor: string,
     *     field_profile_max: string,
     *     sod_groups: list<string>,
     *     pages: list<string>,
     *     actions: list<string>
     * }>
     */
    public function allAssignments(): array
    {
        return $this->assignments ??= $this->build();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pagesForRole(string $code): array
    {
        $assignment = $this->allAssignments()[strtoupper($code)] ?? null;

        if ($assignment === null) {
            return [];
        }

        $catalog = new PermissionResourceCatalog;
        $pages = [];

        foreach ($assignment['pages'] as $pageCode) {
            $page = $catalog->find($pageCode);

            if ($page === null) {
                continue;
            }

            $pages[] = [
                ...$page,
                'default_anchor' => $assignment['default_anchor'],
                'field_profile_max' => $assignment['field_profile_max'],
                'sod_groups' => $assignment['sod_groups'],
            ];
        }

        return $pages;
    }

    /**
     * @return list<string>
     */
    public function rolesForPage(string $pageCode): array
    {
        $roles = [];

        foreach ($this->allAssignments() as $roleCode => $assignment) {
            if (in_array($pageCode, $assignment['pages'], true)) {
                $roles[] = $roleCode;
            }
        }

        sort($roles);

        return $roles;
    }

    /**
     * @return list<string>
     */
    public function missingPageCodes(): array
    {
        $assigned = [];

        foreach ($this->allAssignments() as $assignment) {
            foreach ($assignment['pages'] as $pageCode) {
                $assigned[$pageCode] = true;
            }
        }

        $missing = [];

        foreach ((new PermissionResourceCatalog)->pageCodes() as $pageCode) {
            if (! isset($assigned[$pageCode])) {
                $missing[] = $pageCode;
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function build(): array
    {
        $pages = (new PermissionResourceCatalog)->pages();
        $pageCodes = array_column($pages, 'page_code', 'path');
        $byPath = [];

        foreach ($pages as $page) {
            $byPath[$page['path']] = $page['page_code'];
        }

        $rolePages = [];
        $rolePages['SUPER_ADMIN'] = array_values($pageCodes);
        $rolePages['SYSTEM_ADMIN'] = array_values($pageCodes);
        $rolePages['HR_ADMIN'] = $this->select($pages, [
            '仪表盘', '报表中心', '员工档案', '部门管理', '岗位管理', '员工异动',
            '劳动合同', '考勤打卡', '请假管理', '加班管理', '出差管理',
            '绩效考核', '招聘需求', '候选人', '培训管理', '公告通知',
        ], [
            '/workflow', '/notifications', '/benefits', '/system/settings',
        ]);
        $rolePages['HR_SPECIALIST'] = $this->select($pages, [
            '仪表盘', '报表中心', '员工档案', '部门管理', '岗位管理', '员工异动',
            '劳动合同', '考勤打卡', '请假管理', '加班管理', '出差管理', '公告通知',
        ], ['/notifications', '/system/settings']);
        $rolePages['RECRUITER'] = $this->select($pages, [
            '仪表盘', '报表中心', '招聘需求', '候选人', '公告通知',
        ], ['/workflow', '/notifications']);
        $rolePages['PAYROLL_SPECIALIST'] = $this->select($pages, [
            '仪表盘', '报表中心', '薪资管理',
        ], ['/notifications', '/benefits']);
        $rolePages['PAYROLL_APPROVER'] = $this->select($pages, [
            '仪表盘', '报表中心', '薪资管理',
        ], ['/notifications']);
        $rolePages['ATTENDANCE_SPECIALIST'] = $this->select($pages, [
            '仪表盘', '报表中心', '考勤打卡', '请假管理', '加班管理', '出差管理',
            '公告通知',
        ], ['/notifications', '/system/settings']);
        $rolePages['LINE_MANAGER'] = $this->select($pages, [
            '仪表盘', '报表中心', '员工档案', '考勤打卡', '请假管理', '加班管理',
            '出差管理', '绩效考核', '培训管理', '公告通知',
        ], ['/workflow', '/notifications']);
        $rolePages['EMPLOYEE'] = $this->selectPaths($pages, [
            '/workspace', '/account',
            '/hr/employees', '/hr/employees/:id', '/hr/contracts',
            '/attendance', '/leave', '/overtime', '/travel',
            '/performance', '/training', '/notices', '/notifications', '/benefits',
        ]);
        $rolePages['AUDITOR'] = $this->select($pages, [
            '仪表盘', '报表中心', '公告通知',
        ], ['/system/audit', '/system/pages', '/workflow', '/notifications']);
        $rolePages['TRAINING_ADMIN'] = $this->select($pages, [
            '仪表盘', '报表中心', '培训管理', '公告通知',
        ], ['/notifications']);
        $rolePages['BENEFITS_ADMIN'] = $this->select($pages, [
            '仪表盘', '报表中心',
        ], ['/benefits', '/notifications']);
        $rolePages['REPORT_VIEWER'] = $this->select($pages, [
            '仪表盘', '报表中心',
        ], ['/notifications']);

        $assignments = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $actions = $template->capabilityCodes();
            $anchor = $template->grants[0]->anchorType->value;
            $fieldProfile = $this->fieldProfileMax($template);
            $assigned = $rolePages[$template->code] ?? [];
            $assigned = array_values(array_unique(array_filter(
                $assigned,
                static fn (string $code): bool => in_array($code, $pageCodes, true),
            )));
            sort($assigned);

            $assignments[$template->code] = [
                'role_code' => $template->code,
                'name' => $template->name,
                'default_anchor' => $anchor,
                'field_profile_max' => $fieldProfile->value,
                'sod_groups' => $this->sodGroups($actions),
                'pages' => $assigned,
                'actions' => $actions,
            ];
        }

        $this->assertEveryPageAssigned($assignments, $pages);

        return $assignments;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $menuGroups
     * @param  list<string>  $extraPaths
     * @return list<string>
     */
    private function select(array $pages, array $menuGroups, array $extraPaths): array
    {
        $codes = [];

        // Every authenticated role lands on /workspace and must be able to
        // manage its own password, sessions and optional MFA. These two pages
        // form the "工作台与安全" group, which is not a business menu group and
        // is therefore never listed explicitly by role.
        $baseSecurityPaths = ['/workspace', '/account'];

        foreach ($pages as $page) {
            $isBaseSecurity = in_array($page['path'], $baseSecurityPaths, true);

            if ($isBaseSecurity || in_array($page['menu_group'], $menuGroups, true) || in_array($page['path'], $extraPaths, true)) {
                $codes[] = $page['page_code'];
            }
        }

        return $codes;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function selectPaths(array $pages, array $paths): array
    {
        $codes = [];

        foreach ($pages as $page) {
            if (in_array($page['path'], $paths, true)) {
                $codes[] = $page['page_code'];
            }
        }

        return $codes;
    }

    private function fieldProfileMax(RoleTemplate $template): FieldProfile
    {
        $max = FieldProfile::P0_PUBLIC;

        foreach ($template->grants as $grant) {
            if ($grant->fieldProfile->rank() > $max->rank()) {
                $max = $grant->fieldProfile;
            }
        }

        return $max;
    }

    /**
     * @param  list<string>  $actions
     * @return list<string>
     */
    private function sodGroups(array $actions): array
    {
        $catalog = new CapabilityCatalog;
        $groups = [];

        foreach ($actions as $action) {
            if (CapabilityWildcard::is($action)) {
                foreach ($catalog->all() as $definition) {
                    if ($definition->sodGroup !== null) {
                        $groups[$definition->sodGroup] = true;
                    }
                }

                continue;
            }

            $definition = $catalog->find($action);

            if ($definition?->sodGroup !== null) {
                $groups[$definition->sodGroup] = true;
            }
        }

        $rows = array_keys($groups);
        sort($rows);

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $assignments
     * @param  list<array<string, mixed>>  $pages
     */
    private function assertEveryPageAssigned(array $assignments, array $pages): void
    {
        $assigned = [];

        foreach ($assignments as $assignment) {
            foreach ($assignment['pages'] as $pageCode) {
                $assigned[$pageCode] = true;
            }
        }

        foreach ($pages as $page) {
            if (! isset($assigned[$page['page_code']])) {
                throw new \LogicException("Page {$page['page_code']} has no role assignment.");
            }
        }
    }
}
