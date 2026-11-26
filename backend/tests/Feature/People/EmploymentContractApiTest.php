<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmploymentContract;
use Tests\Support\PeopleTestCase;

final class EmploymentContractApiTest extends PeopleTestCase
{
    public function test_hr_creates_contract_and_renewal_preserves_previous_version(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'CON-0001');
        $scenario->assignment($employment, $scenario->orgUnit('CON-ORG'));

        $created = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'CT-2026-0001',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'signed_on' => '2025-12-20',
            'status' => 'ACTIVE',
        ])->assertCreated();

        $id = (int) $created->json('data.id');

        $renewal = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'CT-2027-0001',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'signed_on' => '2026-12-15',
            'status' => 'ACTIVE',
        ])->assertCreated();

        $renewalId = (int) $renewal->json('data.id');

        $this->assertDatabaseHas('employment_contracts', [
            'id' => $id,
            'status' => 'RENEWED',
            'renewed_by_id' => $renewalId,
        ]);
        $this->assertDatabaseHas('employment_contracts', [
            'id' => $renewalId,
            'previous_contract_id' => $id,
        ]);

        $this->getJson("/v1/employments/{$employment->id}/contracts")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $renewalId)
            ->assertJsonPath('data.items.1.id', $id);
    }

    public function test_expiring_contracts_are_listed_without_duplicates(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'EXP-0001');
        $scenario->assignment($employment, $scenario->orgUnit('EXP-ORG'));

        EmploymentContract::query()->create([
            'employment_id' => $employment->id,
            'contract_no' => 'CT-EXP-1',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-01',
            'signed_on' => '2026-01-01',
            'status' => 'ACTIVE',
            'version' => 1,
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        $this->getJson('/v1/employments/contracts/expiring?within_days=60&as_of=2026-11-23')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.contract_no', 'CT-EXP-1')
            ->assertJsonPath('data.items.0.days_to_expiry', 8);
    }

    public function test_out_of_scope_contract_read_is_404(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'X-CON');
        $con = EmploymentContract::query()->create([
            'employment_id' => $employment->id,
            'contract_no' => 'X-CON-1',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'signed_on' => '2026-01-01',
            'status' => 'ACTIVE',
            'version' => 1,
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        $this->actAs(userId: 999);
        $this->app->instance(
            \App\Shared\Authorization\Authorizer::class,
            new \Tests\Support\DirectoryScopeAuthorizer(
                app(\App\Modules\People\Contracts\PeopleDirectory::class),
                app(\App\Modules\Organization\Contracts\OrganizationDirectory::class),
                'SELF',
            ),
        );

        $this->getJson("/v1/employments/{$employment->id}/contracts/{$con->id}")->assertNotFound();
    }
}
