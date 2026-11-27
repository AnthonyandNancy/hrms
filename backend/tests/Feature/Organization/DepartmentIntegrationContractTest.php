<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

final class DepartmentIntegrationContractTest extends PeopleTestCase
{
    public function test_department_list_and_staffing_do_not_fabricate_missing_facts(): void
    {
        $unit = $this->scenario()->orgUnit('NO-FACTS');

        $this->getJson('/v1/departments?as_of=2026-11-23&q=NO-FACTS')
            ->assertOk()
            ->assertJsonPath('data.items.0.headcount', 0)
            ->assertJsonPath('data.items.0.headcount_limit', null);

        $this->getJson("/v1/departments/staffing?as_of=2026-11-23&org_unit_id={$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.staffed', 0)
            ->assertJsonPath('data.items.0.headcount_limit', null)
            ->assertJsonPath('data.items.0.vacancies', null)
            ->assertJsonPath('data.items.0.overstaffed', false)
            ->assertJsonPath('data.items.0.version', null)
            ->assertJsonPath('data.items.0.effective_from', null);
    }

    public function test_staffing_unknown_department_is_not_found(): void
    {
        $this->getJson('/v1/departments/staffing?as_of=2026-11-23&org_unit_id=999999')
            ->assertNotFound();
    }
}
