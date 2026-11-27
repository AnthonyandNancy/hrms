<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\User;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Shared\Auth\AuthenticatedPrincipal;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AllowAllAuthorizer;
use Tests\Support\PeopleScenario;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

/**
 * The self-service workspace is a SELF read surface. Its profile is composed
 * from the caller's own employment, and the personal task list is constrained
 * by assignee_user_id rather than a request parameter.
 */
final class SelfServiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-11-23T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(Authorizer::class, new AllowAllAuthorizer);
        config()->set('hrms.default_timezone', 'Asia/Shanghai');
    }

    public function test_workspace_projects_only_the_callers_own_profile_and_fields(): void
    {
        [$first, $firstEmployment] = $this->employee('SELF-0001', '合成员工甲', 'self.alpha@example.test');
        $this->employee('SELF-0002', '合成员工乙', 'self.beta@example.test');

        $this->actAs($first);
        $response = $this->getJson('/v1/me/workspace');

        $response->assertOk()
            ->assertJsonPath('data.employment_linked', true)
            ->assertJsonPath('data.profile.employmentId', (int) $firstEmployment->id)
            ->assertJsonPath('data.profile.employeeNo', 'SELF-0001')
            ->assertJsonPath('data.profile.name', '合成员工甲')
            ->assertJsonPath('data.profile.email', 'self.alpha@example.test')
            ->assertJsonPath('data.profile.phone.masked', true);

        // Another synthetic employment must never be projected by this call.
        $response->assertJsonMissing(['employeeNo' => 'SELF-0002']);
        $response->assertJsonMissing(['name' => '合成员工乙']);

        // The projection is allow-list based: salary, identity and bank data
        // are not part of the self-service profile at all.
        foreach (['salary_minor', 'base_salary_minor', 'id_number', 'bank_account', 'mobile'] as $hidden) {
            $response->assertJsonMissingPath('data.profile.'.$hidden);
        }
    }

    public function test_second_user_cannot_read_the_first_users_workspace_data(): void
    {
        [, $firstEmployment] = $this->employee('SELF-0003', '合成员工丙', 'self.gamma@example.test');
        [$second, $secondEmployment] = $this->employee('SELF-0004', '合成员工丁', 'self.delta@example.test');

        $this->actAs($second);
        // Even an explicit employment parameter cannot widen /me/workspace:
        // the controller resolves the caller and ignores request-supplied
        // employment ids.
        $response = $this->getJson('/v1/me/workspace?employment_id='.(int) $firstEmployment->id);

        $response->assertOk()
            ->assertJsonPath('data.profile.employmentId', (int) $secondEmployment->id)
            ->assertJsonPath('data.profile.employeeNo', 'SELF-0004')
            ->assertJsonPath('data.profile.name', '合成员工丁')
            ->assertJsonMissing(['employeeNo' => 'SELF-0003'])
            ->assertJsonMissing(['name' => '合成员工丙']);

        $this->assertNotSame((int) $firstEmployment->id, (int) $response->json('data.profile.employmentId'));
    }

    public function test_my_tasks_are_scoped_to_the_caller_with_due_dates_and_case_facts(): void
    {
        [$first] = $this->employee('SELF-0005', '合成员工戊', 'self.epsilon@example.test');
        [$second] = $this->employee('SELF-0006', '合成员工己', 'self.zeta@example.test');

        $this->task($first, $first, 'SELF-CASE-A', '合成本人审批任务', '2026-11-24T09:00:00Z');
        $this->task($second, $second, 'SELF-CASE-B', '合成他人审批任务', '2026-11-25T09:00:00Z');

        $this->actAs($first);
        $this->getJson('/v1/tasks/my')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.case_no', 'SELF-CASE-A')
            ->assertJsonPath('data.items.0.title', '合成本人审批任务')
            ->assertJsonPath('data.items.0.sla_state', 'ON_TRACK')
            ->assertJsonMissing(['case_no' => 'SELF-CASE-B']);

        $this->assertNotNull($this->getJson('/v1/tasks/my')->json('data.items.0.due_at'));

        $this->actAs($second);
        $this->getJson('/v1/tasks/my')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.case_no', 'SELF-CASE-B')
            ->assertJsonMissing(['case_no' => 'SELF-CASE-A']);
    }

    public function test_user_without_employee_record_gets_an_honest_empty_projection(): void
    {
        $user = User::factory()->create(['username' => 'self.no-employment']);
        $this->actAs($user, null, false);

        $this->getJson('/v1/me/workspace')
            ->assertOk()
            ->assertJsonPath('data.employment_linked', false)
            ->assertJsonPath('data.profile.employmentId', null)
            ->assertJsonPath('data.profile.employeeNo', '')
            ->assertJsonPath('data.profile.name', '')
            ->assertJsonPath('data.profile.initials', '—')
            ->assertJsonPath('data.contract', null)
            ->assertJsonPath('data.leaveBalances', [])
            ->assertJsonPath('data.payslips', []);
    }

    private function actAs(User $user, ?int $employmentId = null, bool $hasEmployment = true): void
    {
        $principal = new TestPrincipal(
            userId: (int) $user->id,
            employmentId: $hasEmployment ? $employmentId ?? $this->linkedEmploymentId($user) : null,
            authVersion: (int) $user->auth_version,
        );

        $this->app->instance(Principal::class, $principal);
        $this->app->instance(AuthenticatedPrincipal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);
    }

    /**
     * @return array{0: User, 1: \App\Modules\People\Models\Employment}
     */
    private function employee(string $employeeNo, string $legalName, string $email): array
    {
        $scenario = new PeopleScenario($this->clock);
        $person = $scenario->person([
            'legal_name' => $legalName,
            'email' => $email,
        ]);
        $employment = $scenario->employment($person, $employeeNo);
        $orgUnit = $scenario->orgUnit('SYN-'.$employeeNo, null, '合成组织');
        $position = $scenario->position($orgUnit, 'POS-'.$employeeNo, ['title' => '合成岗位']);
        $scenario->assignment($employment, $orgUnit, ['position_id' => (int) $position->id]);
        $scenario->terms($employment, 1, ['work_location' => '合成工作地点']);

        $user = User::factory()->create([
            'username' => 'self.'.strtolower($employeeNo),
            'email' => $email,
        ]);
        $scenario->userLink((int) $user->id, $employment);

        return [$user, $employment];
    }

    private function task(User $assignee, User $requester, string $caseNo, string $title, string $dueAt): WorkflowTask
    {
        $case = CaseModel::query()->create([
            'case_no' => $caseNo,
            'type' => 'leave.request',
            'subject_type' => 'employment',
            'subject_id' => 1,
            'title' => $title,
            'payload_json' => [],
            'status' => 'SUBMITTED',
            'effect_status' => 'NONE',
            'requester_user_id' => (int) $requester->id,
            'template_id' => null,
            'template_version' => 1,
            'template_snapshot_json' => [],
            'business_date' => '2026-11-23',
            'submitted_at' => $this->clock->now(),
            'closed_at' => null,
        ]);

        return WorkflowTask::query()->create([
            'case_id' => (int) $case->id,
            'node_key' => 'manager_approval',
            'assignee_user_id' => (int) $assignee->id,
            'status' => 'PENDING',
            'due_at' => $dueAt,
            'acted_at' => null,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function linkedEmploymentId(User $user): ?int
    {
        $id = \App\Modules\People\Models\EmploymentUserLink::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('valid_from')
            ->value('employment_id');

        return $id !== null ? (int) $id : null;
    }
}
