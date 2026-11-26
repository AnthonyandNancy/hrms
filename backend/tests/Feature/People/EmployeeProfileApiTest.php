<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\People\Models\EmployeeContact;
use App\Modules\People\Models\EmployeeEducation;
use Tests\Support\PeopleTestCase;

/**
 * Employee profile subresources are scope-guarded, capability-guarded and
 * return masked P2 values. These tests exercise the real HTTP stack.
 */
final class EmployeeProfileApiTest extends PeopleTestCase
{
    public function test_hr_creates_and_lists_education_for_an_employment(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('EDU-ORG');
        $employment = $scenario->employment($scenario->person(), 'EDU-0001');
        $scenario->assignment($employment, $org);

        $created = $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => 'Synthetic University',
            'degree' => 'BACHELOR',
            'major' => 'Computer Science',
            'start_date' => '2010-09-01',
            'end_date' => '2014-06-30',
        ])->assertCreated();

        $id = (int) $created->json('data.id');
        $this->assertDatabaseHas('employee_educations', [
            'id' => $id,
            'employment_id' => $employment->id,
            'school' => 'Synthetic University',
        ]);

        $this->getJson("/v1/employees/{$employment->id}/education")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $id)
            ->assertJsonPath('data.items.0.degree', 'BACHELOR');
    }

    public function test_contact_phone_is_never_returned_in_plaintext(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('CONTACT-ORG');
        $employment = $scenario->employment($scenario->person(), 'CONTACT-1');
        $scenario->assignment($employment, $org);

        $this->postJson("/v1/employees/{$employment->id}/contacts", [
            'name' => 'Synthetic Contact',
            'relationship' => 'SPOUSE',
            'phone' => '+8613900000000',
            'email' => 'synthetic.contact@example.test',
            'is_emergency' => true,
            'is_primary' => true,
        ])->assertCreated();

        $response = $this->getJson("/v1/employees/{$employment->id}/contacts")->assertOk();
        $response->assertJsonPath('data.items.0.phone.display', null)
            ->assertJsonPath('data.items.0.phone.masked', true)
            ->assertJsonPath('data.items.0.phone.profile', 'P2_SENSITIVE');

        $this->assertStringNotContainsString('+8613900000000', (string) $response->getContent());
        $rawPhone = (string) \Illuminate\Support\Facades\DB::table('employee_contacts')
            ->where('employment_id', $employment->id)
            ->value('phone_ciphertext');
        $this->assertNotSame('+8613900000000', $rawPhone);
        $this->assertTrue(EmployeeContact::query()->where('employment_id', $employment->id)->exists());
    }

    public function test_out_of_scope_profile_read_is_404(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CROSS-1');
        $scenario->assignment($employment, $scenario->orgUnit('CROSS-ORG'));

        $this->actAs(userId: 999);
        $this->app->instance(
            \App\Shared\Authorization\Authorizer::class,
            new \Tests\Support\DirectoryScopeAuthorizer(
                app(\App\Modules\People\Contracts\PeopleDirectory::class),
                app(\App\Modules\Organization\Contracts\OrganizationDirectory::class),
                'SELF',
            ),
        );

        $this->getJson("/v1/employees/{$employment->id}/education")->assertNotFound();
    }

    public function test_missing_write_capability_is_403(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'DENY-1');
        $scenario->assignment($employment, $scenario->orgUnit('DENY-ORG'));

        $this->authorizer->deniedCapabilities = ['people.employee.manage'];

        $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => 'Synthetic University',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ])->assertForbidden();
    }

    public function test_profile_update_is_versioned_by_superseding_the_old_row(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'VER-1');
        $scenario->assignment($employment, $scenario->orgUnit('VER-ORG'));

        $created = $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => 'Old University',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ])->assertCreated();
        $id = (int) $created->json('data.id');

        $this->patchJson("/v1/employees/{$employment->id}/education/{$id}", [
            'school' => 'New University',
        ])->assertOk();

        $old = EmployeeEducation::query()->findOrFail($id);
        $this->assertNotNull($old->getAttribute('superseded_at'));
        $this->assertSame('Old University', (string) $old->getAttribute('school'));

        $current = EmployeeEducation::query()
            ->where('employment_id', $employment->id)
            ->whereNull('superseded_at')
            ->sole();
        $this->assertSame('New University', (string) $current->getAttribute('school'));
    }

    public function test_read_only_capability_cannot_write_profile_subresources(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'RO-ALL');
        $scenario->assignment($employment, $scenario->orgUnit('RO-ALL-ORG'));

        $this->actingAsUserWithCapabilities(['people.employee.read']);

        $payloads = [
            'education' => ['school' => '合成大学', 'degree' => 'BACHELOR', 'start_date' => '2010-09-01'],
            'experiences' => ['company' => '合成前公司', 'title' => '工程师', 'start_date' => '2014-07-01'],
            'contacts' => ['name' => '合成联系人', 'relationship' => 'SPOUSE'],
            'certificates' => ['certificate_type' => 'PROFESSIONAL'],
        ];

        foreach ($payloads as $resource => $payload) {
            $this->postJson("/v1/employees/{$employment->id}/{$resource}", $payload)
                ->assertForbidden();
        }
    }

    public function test_invalid_effective_date_is_422_and_does_not_write_a_row(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'DATE-1');
        $scenario->assignment($employment, $scenario->orgUnit('DATE-ORG'));

        $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2026-09-01',
            'end_date' => '2026-06-30',
        ])->assertStatus(422);

        $this->assertDatabaseCount('employee_educations', 0);
    }

    public function test_change_request_draft_can_be_edited_and_is_read_only_after_submit(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-1');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-ORG'));

        $created = $this->postJson("/v1/employees/{$employment->id}/change_requests", [
            'reason' => '更正合成教育信息',
            'diff' => ['change_type' => 'PROFILE_CORRECTION'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT');

        $id = (int) $created->json('data.id');

        $edited = $this->patchJson("/v1/employees/{$employment->id}/change_requests/{$id}", [
            'reason' => '更正合成教育信息（补充说明）',
            'diff' => ['change_type' => 'PROFILE_CORRECTION'],
        ])->assertOk()
            ->assertJsonPath('data.status', 'DRAFT');

        $draftId = (int) $edited->json('data.id');

        $submitted = $this->postJson("/v1/employees/{$employment->id}/change_requests/{$draftId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.id', $draftId);

        $this->assertNotNull($submitted->json('data.submitted_at'));

        $this->patchJson("/v1/employees/{$employment->id}/change_requests/{$draftId}", [
            'reason' => '提交后不应再修改',
            'diff' => [],
        ])->assertStatus(409)
            ->assertJsonPath('data.reason', 'CHANGE_REQUEST_NOT_DRAFT');

        $this->postJson("/v1/employees/{$employment->id}/change_requests/{$draftId}/submit")
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CHANGE_REQUEST_NOT_DRAFT');
    }

    public function test_change_request_list_exposes_decision_and_approver_when_present(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CHG-DECIDED');
        $scenario->assignment($employment, $scenario->orgUnit('CHG-DECIDED-ORG'));

        $request = EmployeeChangeRequest::query()->create([
            'employment_id' => $employment->id,
            'requested_by' => 10,
            'status' => 'APPROVED',
            'diff_json' => ['change_type' => 'PROFILE_CORRECTION'],
            'reason' => '合成审批完成',
            'case_id' => 88,
            'submitted_at' => '2026-11-20 01:00:00',
            'decided_at' => '2026-11-21 02:00:00',
            'decided_by' => 42,
        ]);

        $this->getJson("/v1/employees/{$employment->id}/change_requests")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $request->id)
            ->assertJsonPath('data.items.0.status', 'APPROVED')
            ->assertJsonPath('data.items.0.decided_by', 42)
            ->assertJsonPath('data.items.0.decided_at', '2026-11-21T02:00:00Z')
            ->assertJsonPath('data.items.0.submitted_at', '2026-11-20T01:00:00Z');
    }

    public function test_profile_create_replays_idempotent_request_without_duplicate_row(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'IDEM-PROFILE');
        $scenario->assignment($employment, $scenario->orgUnit('IDEM-ORG'));

        $payload = [
            'school' => '合成幂等大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ];
        $headers = ['Idempotency-Key' => 'profile-idem-create-1'];

        $first = $this->postJson("/v1/employees/{$employment->id}/education", $payload, $headers)
            ->assertCreated();
        $second = $this->postJson("/v1/employees/{$employment->id}/education", $payload, $headers)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));
        $this->assertDatabaseCount('employee_educations', 1);
    }

    public function test_submit_replays_idempotently_and_still_rejects_a_second_logical_submit(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'IDEM-SUBMIT');
        $scenario->assignment($employment, $scenario->orgUnit('IDEM-SUBMIT-ORG'));

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/change_requests", [
            'reason' => '提交幂等测试',
            'diff' => [],
        ])->assertCreated()->json('data.id');

        $headers = ['Idempotency-Key' => 'profile-idem-submit-1'];
        $first = $this->postJson("/v1/employees/{$employment->id}/change_requests/{$id}/submit", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED');
        $second = $this->postJson("/v1/employees/{$employment->id}/change_requests/{$id}/submit", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED');

        $this->assertSame($first->json('data.submitted_at'), $second->json('data.submitted_at'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $this->postJson("/v1/employees/{$employment->id}/change_requests/{$id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CHANGE_REQUEST_NOT_DRAFT');
    }

    public function test_archived_employee_keeps_historical_documents_readable(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'ARCH-DOC');
        $scenario->assignment($employment, $scenario->orgUnit('ARCH-DOC-ORG'));
        $fileObjectId = $scenario->fileObject();
        $document = $scenario->document($employment, $fileObjectId, [
            'category' => 'OTHER',
            'retention_class' => 'GENERAL_2Y',
        ]);

        $employment->forceFill([
            'archived_at' => $this->clock->now(),
            'archived_by' => 10,
            'archive_reason' => '合成归档',
        ])->save();

        $this->actingAsUserWithCapabilities(['people.employee.read', 'people.document.read', 'people.document.download']);

        $this->getJson("/v1/employments/{$employment->id}/document-files")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $document->id)
            ->assertJsonPath('data.items.0.original_name', 'contract.pdf');
    }
}
