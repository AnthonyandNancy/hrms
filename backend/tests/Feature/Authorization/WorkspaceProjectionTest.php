<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\User;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\Position;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\EmploymentUserLink;
use App\Modules\People\Models\Person;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AllowAllAuthorizer;
use Tests\TestCase;

/**
 * /v1/me/workspace must project the caller's real employment, terms and
 * organization facts instead of placeholder nulls. It is a self-service read
 * surface: no other employee may be visible through it.
 */
final class WorkspaceProjectionTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(Authorizer::class, new AllowAllAuthorizer);
    }

    public function test_workspace_projects_the_callers_own_employment_and_terms(): void
    {
        [$user, $employment] = $this->seedEmploymentWithTerms('DEMO-9001', '徐清和');
        $this->actAs($user);

        $response = $this->getJson('/v1/me/workspace');

        $response->assertOk()
            ->assertJsonPath('data.employment_linked', true)
            ->assertJsonPath('data.profile.employmentId', (int) $employment->id)
            ->assertJsonPath('data.profile.employeeNo', 'DEMO-9001')
            ->assertJsonPath('data.profile.name', '徐清和')
            ->assertJsonPath('data.profile.jobTitle', '高级人事专员')
            ->assertJsonPath('data.profile.orgName', '人力资源部')
            ->assertJsonPath('data.profile.workLocation', '上海 · 张江')
            ->assertJsonPath('data.profile.email', 'qinghe.xu@example.test')
            ->assertJsonPath('data.profile.managerName', '演示经理')
            ->assertJsonPath('data.contract.startDate', '2026-01-01')
            ->assertJsonPath('data.contract.status', '生效中')
            ->assertJsonPath('data.contract.type', '全职')
            ->assertJsonPath('data.contract.endDate', null)
            ->assertJsonStructure([
                'data' => [
                    'attendanceSummary' => ['expectedDays', 'presentDays', 'lateCount', 'absentCount', 'leaveDays', 'remoteDays'],
                    'attendanceMonth',
                    'leaveBalances',
                    'myRequests',
                    'payslips',
                    'notices',
                    'freshness',
                ],
            ]);

        // The self-service projection is scoped to the caller; another
        // synthetic employment in the same tenant must not appear.
        $response->assertJsonMissing(['employeeNo' => 'DEMO-9002']);
    }

    public function test_workspace_without_an_employment_returns_an_empty_but_well_formed_projection(): void
    {
        $user = User::factory()->create();
        $this->actAs($user);

        $response = $this->getJson('/v1/me/workspace');

        $response->assertOk()
            ->assertJsonPath('data.employment_linked', false)
            ->assertJsonPath('data.profile.employmentId', null)
            ->assertJsonPath('data.contract', null)
            ->assertJsonStructure([
                'data' => [
                    'profile' => ['name', 'initials', 'employeeNo', 'jobTitle', 'orgName', 'phone'],
                    'contract',
                    'attendanceSummary',
                    'leaveBalances',
                    'myRequests',
                    'payslips',
                    'notices',
                    'freshness',
                ],
            ]);
    }

    private function actAs(User $user): void
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            employmentId: null,
            authVersion: (int) $user->auth_version,
            privileged: false,
            mfa: false,
        );
        $this->app->instance(Principal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);
    }

    /**
     * @return array{0: User, 1: Employment}
     */
    private function seedEmploymentWithTerms(string $employeeNo, string $legalName): array
    {
        $managerPerson = Person::query()->create([
            'legal_name' => '演示经理',
            'email' => 'manager@example.test',
        ]);
        $managerEmployment = Employment::query()->create([
            'person_id' => (int) $managerPerson->id,
            'employee_no' => 'DEMO-9000',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => $this->clock->now(),
        ]);

        $orgUnit = OrgUnit::query()->create([
            'parent_id' => null,
            'code' => 'HR',
            'name' => '人力资源部',
            'type' => 'DEPARTMENT',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
        ]);

        $position = Position::query()->create([
            'org_unit_id' => (int) $orgUnit->id,
            'code' => 'HR-SPECIALIST',
            'title' => '高级人事专员',
            'grade' => 'P6',
            'headcount_limit' => 3,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
        ]);

        $managerAssignment = Assignment::query()->create([
            'employment_id' => (int) $managerEmployment->id,
            'org_unit_id' => (int) $orgUnit->id,
            'position_id' => null,
            'manager_assignment_id' => null,
            'assignment_type' => 'PRIMARY',
            'is_primary' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
        ]);

        $person = Person::query()->create([
            'legal_name' => $legalName,
            'email' => 'qinghe.xu@example.test',
        ]);

        $employment = Employment::query()->create([
            'person_id' => (int) $person->id,
            'employee_no' => $employeeNo,
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => $this->clock->now(),
        ]);

        Assignment::query()->create([
            'employment_id' => (int) $employment->id,
            'org_unit_id' => (int) $orgUnit->id,
            'position_id' => (int) $position->id,
            'manager_assignment_id' => (int) $managerAssignment->id,
            'assignment_type' => 'PRIMARY',
            'is_primary' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
        ]);

        EmploymentTerms::query()->create([
            'employment_id' => (int) $employment->id,
            'version' => 1,
            'effective_from' => '2026-01-01',
            'effective_to' => '9999-12-31',
            'probation_end_date' => null,
            'work_location' => '上海 · 张江',
            'work_schedule' => 'STANDARD_5X8',
            'salary_scheme_code' => null,
            'base_salary_minor' => 1_800_000,
            'salary_currency' => 'CNY',
            'notice_period_days' => 30,
            'confidentiality' => true,
            'non_compete' => false,
            'signed_at' => $this->clock->now(),
            'recorded_at' => $this->clock->now(),
        ]);

        $user = User::factory()->create(['username' => 'demo-9001']);
        EmploymentUserLink::query()->create([
            'user_id' => (int) $user->id,
            'employment_id' => (int) $employment->id,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
        ]);

        return [$user, $employment];
    }
}
