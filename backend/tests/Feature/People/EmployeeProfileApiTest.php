<?php

declare(strict_types=1);

namespace Tests\Feature\People;

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
}
