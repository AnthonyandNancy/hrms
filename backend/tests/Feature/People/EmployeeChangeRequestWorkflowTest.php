<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\People\Services\EmployeeProfileService;
use Tests\Support\PeopleTestCase;

/**
 * Submit creates a real Workflow Case through the approval port and persists
 * its id. The approved effect is the only writer of the versioned profile row.
 */
final class EmployeeChangeRequestWorkflowTest extends PeopleTestCase
{
    private function seedHrApprover(): int
    {
        Role::query()->firstOrCreate(
            ['code' => 'HR_ADMIN'],
            ['name' => 'HR administrator', 'is_template' => true, 'purpose' => 'Synthetic test role'],
        );
        $user = User::query()->create([
            'username' => 'synthetic.hr.approver',
            'email' => 'synthetic.hr.approver@example.test',
            'password_hash' => 'synthetic-not-a-login',
            'status' => 'ACTIVE',
            'auth_version' => 1,
            'is_privileged' => false,
        ]);
        UserRole::query()->create([
            'user_id' => (int) $user->id,
            'role_code' => 'HR_ADMIN',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'reason' => 'Synthetic test approval assignment',
            'auth_version' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        return (int) $user->id;
    }

    public function test_submit_opens_a_real_case_and_persists_case_id_and_number(): void
    {
        $this->seedHrApprover();
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-WF-1');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-WF-ORG'));

        $recordId = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'chg-wf-education'])->assertCreated()->json('data.id');

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/change_requests", [
            'reason' => '合成变更',
            'diff' => [
                'change_type' => 'PROFILE_CORRECTION',
                'resource' => 'education',
                'record_id' => $recordId,
                'changes' => ['major' => '合成专业'],
            ],
        ], ['Idempotency-Key' => 'chg-wf-create'])->assertCreated()->json('data.id');

        $submitted = $this->postJson(
            "/v1/employees/{$employment->id}/change_requests/{$id}/submit",
            [],
            ['Idempotency-Key' => 'chg-wf-1'],
        )->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

        $caseId = (int) $submitted->json('data.case_id');
        $this->assertGreaterThan(0, $caseId);
        $this->assertNotEmpty($submitted->json('data.case_no'));

        $this->assertDatabaseHas('employee_change_requests', [
            'id' => $id,
            'case_id' => $caseId,
            'status' => 'SUBMITTED',
        ]);
    }

    public function test_the_same_idempotency_key_replays_the_same_case_instead_of_opening_another(): void
    {
        $this->seedHrApprover();
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-WF-2');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-WF-ORG-2'));

        $recordId = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'chg-wf-education-2'])->assertCreated()->json('data.id');

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/change_requests", [
            'reason' => '合成变更',
            'diff' => [
                'change_type' => 'PROFILE_CORRECTION',
                'resource' => 'education',
                'record_id' => $recordId,
                'changes' => ['major' => '合成专业'],
            ],
        ], ['Idempotency-Key' => 'chg-wf-create-2'])->assertCreated()->json('data.id');

        $headers = ['Idempotency-Key' => 'chg-wf-submit-2'];
        $first = $this->postJson("/v1/employees/{$employment->id}/change_requests/{$id}/submit", [], $headers)->assertOk();
        $second = $this->postJson("/v1/employees/{$employment->id}/change_requests/{$id}/submit", [], $headers)->assertOk();

        $this->assertSame($first->json('data.case_id'), $second->json('data.case_id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $this->postJson("/v1/employees/{$employment->id}/change_requests/{$id}/submit", [], ['Idempotency-Key' => 'chg-wf-submit-2b'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CHANGE_REQUEST_NOT_DRAFT');
    }

    public function test_approved_effect_applies_only_whitelisted_fields_and_replays_once(): void
    {
        $this->seedHrApprover();
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-WF-3');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-WF-ORG-3'));

        $recordId = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'major' => '旧专业',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'chg-wf-education-3'])->assertCreated()->json('data.id');

        $request = EmployeeChangeRequest::query()->create([
            'employment_id' => $employment->id,
            'requested_by' => 10,
            'status' => 'SUBMITTED',
            'reason' => '合成变更',
            'diff_json' => [
                'change_type' => 'PROFILE_CORRECTION',
                'resource' => 'education',
                'record_id' => $recordId,
                'changes' => ['major' => '合成新专业', 'employment_id' => 999],
            ],
            'case_id' => 4242,
        ]);

        $service = app(EmployeeProfileService::class);
        $first = $service->applyApprovedChange(
            employmentId: (int) $employment->id,
            changeRequestId: (int) $request->id,
            resource: 'education',
            recordId: $recordId,
            changes: ['major' => '合成新专业', 'employment_id' => 999],
            approverUserId: 42,
            caseId: 4242,
        );
        $second = $service->applyApprovedChange(
            employmentId: (int) $employment->id,
            changeRequestId: (int) $request->id,
            resource: 'education',
            recordId: $recordId,
            changes: ['major' => '合成新专业', 'employment_id' => 999],
            approverUserId: 42,
            caseId: 4242,
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertDatabaseHas('employee_educations', [
            'employment_id' => $employment->id,
            'school' => '合成大学',
            'major' => '合成新专业',
            'superseded_at' => null,
        ]);
        $this->assertDatabaseHas('employee_change_requests', [
            'id' => $request->id,
            'status' => 'APPROVED',
            'case_id' => 4242,
            'decided_by' => 42,
        ]);
        $this->assertSame(2, \App\Modules\People\Models\EmployeeEducation::query()
            ->where('employment_id', $employment->id)
            ->count());
    }

    public function test_effect_rejects_a_case_id_that_does_not_own_the_change_request(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-WF-4');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-WF-ORG-4'));

        $request = EmployeeChangeRequest::query()->create([
            'employment_id' => $employment->id,
            'requested_by' => 10,
            'status' => 'SUBMITTED',
            'reason' => '合成变更',
            'diff_json' => [
                'resource' => 'education',
                'record_id' => 1,
                'changes' => ['major' => '合成专业'],
            ],
            'case_id' => 100,
        ]);

        $this->expectException(\App\Shared\Exceptions\ConflictException::class);

        app(EmployeeProfileService::class)->applyApprovedChange(
            employmentId: (int) $employment->id,
            changeRequestId: (int) $request->id,
            resource: 'education',
            recordId: 1,
            changes: ['major' => '合成专业'],
            approverUserId: 42,
            caseId: 999,
        );
    }
}
