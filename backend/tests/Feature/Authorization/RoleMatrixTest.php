<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Shared\Authorization\AnchorType;
use Tests\Support\AuthorizationMatrixScenario;

/**
 * Volume 2 Task 11: the five role identities exercised against the real
 * capability engine.
 *
 * Every denial asserts a reason code, not just a 403, so an over-broad
 * validation failure can never be mistaken for a working permission boundary.
 */
final class RoleMatrixTest extends AuthorizationMatrixScenario
{
    /** @var list<string> */
    private const ROLES = [
        // The plan says "department manager"; the code-owned role is LINE_MANAGER.
        'LINE_MANAGER',
        'HR_ADMIN',
        'LINE_MANAGER',
        'EMPLOYEE',
        'PAYROLL_SPECIALIST',
    ];

    public function test_each_role_can_authenticate_and_reads_its_own_permission_catalog(): void
    {
        foreach (self::ROLES as $role) {
            $user = $this->userWithRole($role);
            $this->loginAs($user);

            $this->getJson('/v1/me')
                ->assertOk()
                ->assertJsonPath('data.id', (int) $user->id);

            $this->getJson('/v1/me/permissions')
                ->assertOk()
                ->assertJsonStructure(['data' => ['menus', 'actions', 'pages', 'field_capabilities', 'scope_labels']]);
        }
    }

    public function test_user_administration_is_limited_to_the_system_administrator(): void
    {
        $admin = $this->userWithRole('SYSTEM_ADMIN');
        $this->loginAs($admin);
        $this->getJson('/v1/users')->assertOk();
        $this->postJson('/v1/users', $this->newUserPayload('admin'))
            ->assertCreated();

        // HR_ADMIN may read and provision accounts by design; the boundary that
        // matters is that a line manager cannot.
        $hr = $this->userWithRole('HR_ADMIN');
        $this->loginAs($hr);
        $this->getJson('/v1/users')->assertOk();

        $manager = $this->userWithRole('LINE_MANAGER');
        $this->loginAs($manager);
        $this->postJson('/v1/users', $this->newUserPayload('manager'))
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');

        $employee = $this->userWithRole('EMPLOYEE');
        $this->loginAs($employee);
        $this->getJson('/v1/users')
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');
        $this->postJson('/v1/users', $this->newUserPayload('employee'))
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');
    }

    public function test_payroll_specialist_reads_payroll_runs_but_not_user_administration(): void
    {
        $payroll = $this->userWithRole('PAYROLL_SPECIALIST');
        $this->loginAs($payroll);

        // Volume 3 Task 12 fixed the Volume 2 finding: the runs index is no
        // longer COMPANY-gated. The guard answers "may work with runs at all"
        // and the controller restricts rows to runs the principal is a member of
        // (the specialist is a member of none yet, so the list is empty — but it
        // is authorised, which is the defect that was recorded).
        $this->getJson('/v1/payroll/runs')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->postJson('/v1/users', $this->newUserPayload('payroll'))
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');
    }

    public function test_employee_role_cannot_reach_the_employee_directory(): void
    {
        $employee = $this->userWithRole('EMPLOYEE');
        $this->loginAs($employee);

        $this->getJson('/v1/employees')
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');
    }

    public function test_line_manager_is_scoped_to_managed_reports_only(): void
    {
        // FINDING (Volume 2 Task 11): the plan calls this persona "department
        // manager" with an ORG_UNIT anchor, but the code-owned LINE_MANAGER
        // role grants MANAGED (direct reports). It therefore cannot read the
        // company employee directory; that is recorded in the audit document.
        $manager = $this->userWithRole('LINE_MANAGER');
        $this->loginAs($manager);

        $this->getJson('/v1/employees')
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');

        // The MANAGED anchor is satisfied only through the people directory,
        // so an unmanaged employee is out of scope even for a capability the
        // manager does hold.
        // FINDING: an out-of-scope employee is answered with 403
        // CAPABILITY_DENIED here, not 404 OUT_OF_SCOPE. Both deny correctly, but
        // the 403 reveals that the id exists. Recorded for the audit document.
        $other = $this->employeeIn($this->deptB());
        $this->getJson('/v1/employees/'.$other->id)
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');
    }

    public function test_hr_without_the_export_capability_is_denied_the_export_endpoint(): void
    {
        $hr = $this->hrWithoutP3();
        $this->loginAs($hr);

        // The HR template has no reporting.export grant, so the route gate must
        // deny before any field-level decision is made.
        $this->postJson('/v1/reporting/exports', [
            'reportCode' => 'WORKFORCE_HEADCOUNT',
            'purpose' => 'BUSINESS_OPERATION',
            'fields' => ['employee_no'],
            'asOf' => '2026-11-23',
        ], ['Idempotency-Key' => 'matrix-export-denied'])
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');
    }

    public function test_field_ceiling_is_enforced_for_an_export_holder_without_p3(): void
    {
        // A purpose-built identity: the export capability at a P2 ceiling.
        // HR_ADMIN already carries reporting.export at P3 through the template.
        $user = $this->userWithoutTemplateGrants();
        $this->bindDirect(
            $user,
            'reporting.export',
            AnchorType::COMPANY,
            null,
            \App\Shared\Authorization\FieldProfile::P2_SENSITIVE,
        );
        $this->bindDirect(
            $user,
            'iam.session.manage',
            AnchorType::SELF,
            null,
            \App\Shared\Authorization\FieldProfile::P0_PUBLIC,
        );
        $this->loginAs($user);

        $this->postJson('/v1/reporting/exports', [
            'reportCode' => 'PAYROLL_COST',
            'purpose' => 'BUSINESS_OPERATION',
            'fields' => ['salary'],
            'asOf' => '2026-11-23',
        ], ['Idempotency-Key' => 'matrix-export-p3'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.reason', 'EXPORT_FIELD_HIDDEN');
    }

    /**
     * @return array<string, string>
     */
    private function newUserPayload(string $tag): array
    {
        return [
            'username' => 'matrix.'.$tag.'.'.bin2hex(random_bytes(4)),
            'email' => 'matrix.'.$tag.'.'.bin2hex(random_bytes(3)).'@example.invalid',
            'password' => 'Synthetic-Pass-2026!',
        ];
    }
}
