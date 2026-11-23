<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Employment;
use Tests\Support\PeopleTestCase;

final class CrossLegalEntityTest extends PeopleTestCase
{
    public function test_assignment_to_another_legal_entity_is_rejected_with_reason_code(): void
    {
        $scenario = $this->scenario();
        $cn = $scenario->legalEntity('DEMO-CN');
        $sg = $scenario->legalEntity('DEMO-SG', 'Demo Singapore', 'Asia/Singapore', 'SGD');
        $orgInSingapore = $scenario->orgUnit($sg, 'SG-OPS');
        $person = $scenario->person();
        $employment = $scenario->employment($person, $cn, 'X-1');

        $response = $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $orgInSingapore->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-01-01',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            // The stable reason is carried in the error envelope; message text is user-facing.
            ->assertJsonPath('data.reason', 'CROSS_LEGAL_ENTITY_ASSIGNMENT')
            ->assertJsonPath('data.fields.org_unit_id.0', 'CROSS_LEGAL_ENTITY_ASSIGNMENT: assignment organization must belong to the employment legal entity.');
    }

    public function test_cross_entity_transfer_ends_old_and_creates_new_employment_for_same_person(): void
    {
        $scenario = $this->scenario();
        $cn = $scenario->legalEntity('DEMO-CN');
        $sg = $scenario->legalEntity('DEMO-SG', 'Demo Singapore', 'Asia/Singapore', 'SGD');
        $person = $scenario->person();
        $old = $scenario->employment($person, $cn, 'TR-1');

        $response = $this->postJson("/v1/employments/{$old->id}/transfer", [
            'legal_entity_id' => $sg->id,
            'effective_from' => '2026-12-01',
            'employee_no' => 'TR-SG-1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.person_id', $person->id)
            ->assertJsonPath('data.legal_entity_id', $sg->id);

        $old->refresh();
        $this->assertSame('2026-12-01', $old->valid_to);
        $this->assertSame('TERMINATED', $old->status);
        $this->assertNotNull($old->superseded_at);

        $newId = (int) $response->json('data.employment_id');
        $this->assertSame($old->person_id, Employment::query()->findOrFail($newId)->person_id);
    }

    public function test_employee_number_is_unique_per_legal_entity_only(): void
    {
        $scenario = $this->scenario();
        $cn = $scenario->legalEntity('DEMO-CN');
        $sg = $scenario->legalEntity('DEMO-SG', 'Demo Singapore', 'Asia/Singapore', 'SGD');
        $person = $scenario->person();
        $scenario->employment($person, $cn, 'SHARED-1');

        $duplicate = $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'legal_entity_id' => $cn->id,
            'employee_no' => 'SHARED-1',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-02-01',
            'hire_date' => '2026-02-01',
        ]);

        $duplicate->assertConflict()->assertJsonPath('data.reason', 'EMPLOYEE_NO_DUPLICATE');

        $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'legal_entity_id' => $sg->id,
            'employee_no' => 'SHARED-1',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-02-01',
            'hire_date' => '2026-02-01',
        ])->assertCreated();
    }
}
