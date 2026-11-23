<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Person;
use Tests\Support\PeopleTestCase;

final class CrossTenantIsolationTest extends PeopleTestCase
{
    public function test_person_service_never_reads_another_tenants_person(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $person = $scenario->person();

        $this->actAs(tenantId: 2, userId: 20);

        $this->postJson('/v1/employments', [
            'person_id' => $person->id,
            'legal_entity_id' => $entity->id,
            'employee_no' => 'CROSS-1',
            'employment_type' => 'FULL_TIME',
            'valid_from' => '2026-01-01',
            'hire_date' => '2026-01-01',
        ])->assertUnprocessable();
    }

    public function test_person_dedup_is_tenant_scoped(): void
    {
        $payload = ['legal_name' => 'Same Identity', 'id_type' => 'NATIONAL_ID', 'id_number' => 'TENANT-SAME'];
        $this->postJson('/v1/persons', $payload)->assertCreated();

        $this->actAs(tenantId: 2, userId: 21);
        $this->postJson('/v1/persons', $payload)->assertCreated();

        $this->assertSame(1, Person::query()->where('tenant_id', 1)->count());
        $this->assertSame(1, Person::query()->where('tenant_id', 2)->count());
    }

    public function test_assignment_route_404s_for_other_tenant_employment(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'TENANT-ORG-2');
        $employment = $scenario->employment($scenario->person(), $entity, 'TENANT-EMP-2');

        $this->actAs(tenantId: 2, userId: 22);

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $org->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-01-01',
        ])->assertNotFound();
    }
}
