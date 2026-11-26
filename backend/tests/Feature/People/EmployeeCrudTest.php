<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Employment;
use Tests\Support\PeopleTestCase;

final class EmployeeCrudTest extends PeopleTestCase
{
    public function test_archive_hides_from_default_list_preserves_history_and_can_be_restored(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();
        $employment = $scenario->employment($person, $entity, 'ARCH-0001');
        $scenario->assignment($employment, $scenario->orgUnit($entity, 'ARCH-ORG'));

        $this->deleteJson("/v1/employees/{$employment->id}", [
            'reason' => 'Synthetic leaver archive',
        ])->assertOk()->assertJsonPath('data.employment_id', (int) $employment->id);

        $archived = Employment::query()->findOrFail($employment->id);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame(10, (int) $archived->archived_by);
        $this->assertSame('Synthetic leaver archive', (string) $archived->archive_reason);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'people.employee.archived',
            'aggregate_id' => (string) $employment->id,
        ]);

        $this->getJson('/v1/employees')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/v1/employees/'.$employment->id)->assertOk()
            ->assertJsonPath('data.employment_id', (int) $employment->id);

        $this->postJson("/v1/employees/{$employment->id}/restore")->assertOk();
        $restored = Employment::query()->findOrFail($employment->id);
        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->archived_by);
        $this->assertNull($restored->archive_reason);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'people.employee.restored',
            'aggregate_id' => (string) $employment->id,
        ]);
        $this->getJson('/v1/employees')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_include_archived_is_an_explicit_opt_in(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'ARCH-0002');
        $scenario->assignment($employment, $scenario->orgUnit($entity, 'ARCH-ORG-2'));

        $this->deleteJson("/v1/employees/{$employment->id}", ['reason' => 'Synthetic archive'])->assertOk();

        $this->getJson('/v1/employees?include_archived=1')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.archived_by', 10);
    }

    public function test_employee_number_is_never_reused_after_archive(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'ARCH-0003');
        $scenario->assignment($employment, $scenario->orgUnit($entity, 'ARCH-ORG-3'));
        $this->deleteJson("/v1/employees/{$employment->id}", ['reason' => 'Synthetic archive'])->assertOk();

        $this->postJson('/v1/employments', [
            'person_id' => (int) $scenario->person()->id,
            'legal_entity_id' => (int) $entity->id,
            'employee_no' => 'ARCH-0003',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-11-01',
            'hire_date' => '2026-11-01',
        ], ['Idempotency-Key' => 'employee-reuse-1'])->assertStatus(409)
            ->assertJsonPath('data.reason', 'EMPLOYEE_NO_DUPLICATE');
    }
}
