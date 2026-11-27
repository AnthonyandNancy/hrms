<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use Tests\Support\PeopleTestCase;

final class ModuleContractBoundaryTest extends PeopleTestCase
{
    public function test_organization_facts_contract_is_bound_and_resolves_company_facts(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('FACTS-ORG');

        $facts = app(OrganizationFacts::class);
        $unitInfo = $facts->orgUnit($unit->id);

        $this->assertNotNull($unitInfo);
        $this->assertSame('FACTS-ORG', $unitInfo->code);
        $this->assertNull($facts->orgUnit(999999));
        $this->assertSame('Asia/Shanghai', $facts->defaultTimezone());
    }

    public function test_people_impact_contract_is_used_by_org_impact_preview(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('IMPACT-CONTRACT');
        $employment = $scenario->employment($scenario->person(), 'IMPACT-CONTRACT-1');
        $assignment = $scenario->assignment($employment, $org);

        $impact = app(OrgAssignmentImpactDirectory::class)->impactCounts([$org->id], '2026-11-23');

        $this->assertSame(1, $impact->employeeCount);
        $this->assertSame(0, $impact->managerCount);

        $this->getJson("/v1/org-units/{$org->id}/impact?new_parent_id=&as_of=2026-11-23")
            ->assertOk()
            ->assertJsonPath('data.affected_employee_count', 1);

        $this->assertNotNull($assignment->id);
    }

    public function test_demo_seeder_contract_is_idempotent_and_org_owned(): void
    {
        $seeder = app(DemoOrganizationSeeder::class);
        $first = $seeder->seedDemo();
        $second = $seeder->seedDemo();

        $this->assertSame($first, $second);
        $this->assertSame(4, count($first['org_unit_ids']));
        $this->assertDatabaseHas('company_profile', ['id' => 1]);
    }
}
