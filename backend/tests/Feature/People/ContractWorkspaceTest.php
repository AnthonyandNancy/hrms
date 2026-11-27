<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Lifecycle\Models\EmploymentContractVersion;
use App\Modules\People\Models\EmploymentContract;
use Tests\Support\PeopleTestCase;

final class ContractWorkspaceTest extends PeopleTestCase
{
    public function test_hr_creates_a_draft_contract_and_the_creation_is_versioned(): void
    {
        $employment = $this->employment('WS-0001');

        $created = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0001',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'probation_end_date' => '2026-04-01',
            'work_location' => '上海合成办公室',
            'signed_on' => '2025-12-20',
            'status' => 'DRAFT',
            'terms_summary' => 'Synthetic draft terms',
        ])->assertCreated()
            ->assertJsonPath('data.contract_no', 'WS-C-0001')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1);

        $contractId = (int) $created->json('data.id');

        $this->assertDatabaseHas('employment_contracts', [
            'id' => $contractId,
            'employment_id' => $employment->id,
            'status' => 'DRAFT',
            'version' => 1,
        ]);

        $history = $this->getJson("/v1/employment-contracts/{$contractId}/amendments")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.change_type', 'CREATION')
            ->assertJsonPath('data.items.0.effective_from', '2026-01-01')
            ->json('data.items.0.snapshot');

        $this->assertSame('上海合成办公室', $history['work_location']);
        $this->assertSame('2026-04-01', $history['probation_end_date']);
    }

    public function test_invalid_end_date_and_probation_are_rejected_with_field_errors(): void
    {
        $employment = $this->employment('WS-0002');

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0002-A',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-06-01',
            'end_date' => '2026-05-01',
            'signed_on' => '2026-05-01',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['data' => ['fields' => ['end_date']]]);

        // Probation may not outlive the contract.
        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0002-B',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'probation_end_date' => '2027-01-01',
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['fields' => ['probation_end_date']]]);

        // Probation may also not exceed six months.
        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0002-C',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'probation_end_date' => '2026-08-01',
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['fields' => ['probation_end_date']]]);

        // Probation may not start before the contract.
        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0002-D',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'probation_end_date' => '2025-12-31',
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['fields' => ['probation_end_date']]]);

        $this->assertDatabaseCount('employment_contracts', 0);
    }

    public function test_renewal_requires_a_reason_and_appends_a_version_without_overwriting_history(): void
    {
        $employment = $this->employment('WS-0003');

        $initial = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0003',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'signed_on' => '2025-12-20',
            'status' => 'ACTIVE',
        ])->assertCreated();

        $initialId = (int) $initial->json('data.id');

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0004-NO-REASON',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'renewal_of_contract_id' => $initialId,
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['fields' => ['reason']]]);

        $renewal = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0004',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'signed_on' => '2026-12-15',
            'status' => 'ACTIVE',
            'renewal_of_contract_id' => $initialId,
            'reason' => 'Synthetic renewal reason',
        ])->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.previous_contract_id', $initialId);

        $renewalId = (int) $renewal->json('data.id');

        $this->assertDatabaseHas('employment_contracts', [
            'id' => $initialId,
            'status' => 'RENEWED',
            'renewed_by_id' => $renewalId,
            'contract_no' => 'WS-C-0003',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $this->assertDatabaseHas('employment_contracts', [
            'id' => $renewalId,
            'previous_contract_id' => $initialId,
            'status' => 'ACTIVE',
        ]);

        $this->assertSame(2, EmploymentContractVersion::query()
            ->where('employment_id', $employment->id)
            ->count());
        $this->assertDatabaseHas('employment_contract_versions', [
            'contract_id' => $initialId,
            'change_type' => 'CREATION',
        ]);
        $this->assertDatabaseHas('employment_contract_versions', [
            'contract_id' => $renewalId,
            'change_type' => 'RENEWAL',
            'reason' => 'Synthetic renewal reason',
        ]);

        $this->getJson("/v1/employment-contracts/{$renewalId}/amendments")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.change_type', 'RENEWAL')
            ->assertJsonPath('data.items.0.snapshot.previous_contract_id', $initialId);
    }

    public function test_terminating_a_contract_keeps_versions_and_sets_the_effective_end_date(): void
    {
        $employment = $this->employment('WS-0004');

        $contractId = (int) $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0005',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'signed_on' => '2025-12-20',
            'status' => 'ACTIVE',
        ])->assertCreated()->json('data.id');

        $this->postJson("/v1/employment-contracts/{$contractId}/terminate", [
            'effective_date' => '2026-09-30',
            'reason' => 'Synthetic termination',
        ], ['Idempotency-Key' => 'contract-workspace-terminate-1'])
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'TERMINATED')
            ->assertJsonPath('data.contract.end_date', '2026-09-30')
            ->assertJsonPath('data.version.change_type', 'TERMINATION');

        $contract = EmploymentContract::query()->whereKey($contractId)->firstOrFail();
        $this->assertSame('2026-09-30', (string) $contract->end_date);
        $this->assertSame('2026-09-30', (string) $contract->terminated_on);
        $this->assertSame(2, EmploymentContractVersion::query()
            ->where('contract_id', $contractId)
            ->count());
        $this->assertDatabaseHas('employment_contract_versions', [
            'contract_id' => $contractId,
            'change_type' => 'CREATION',
        ]);
        $this->assertDatabaseHas('employment_contract_versions', [
            'contract_id' => $contractId,
            'change_type' => 'TERMINATION',
            'reason' => 'Synthetic termination',
        ]);
    }

    public function test_contract_creation_renewal_and_termination_are_capability_guarded(): void
    {
        $employment = $this->employment('WS-0005');

        $contractId = (int) $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0006',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
        ])->assertCreated()->json('data.id');

        $this->actAs(userId: 999);
        $this->actingAsUserWithCapabilities(['people.contract.read']);

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'WS-C-0007',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'reason' => 'Unauthorized renewal attempt',
        ])->assertForbidden();

        $this->postJson("/v1/employment-contracts/{$contractId}/terminate", [
            'effective_date' => '2026-09-30',
            'reason' => 'Unauthorized termination attempt',
        ], ['Idempotency-Key' => 'contract-workspace-forbidden-1'])
            ->assertForbidden();

        // Reading remains allowed for the read-only role.
        $this->getJson('/v1/employments/contracts')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    private function employment(string $employeeNo): \App\Modules\People\Models\Employment
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), $employeeNo);
        $scenario->assignment($employment, $scenario->orgUnit('WS-ORG-'.$employeeNo));

        return $employment;
    }
}
