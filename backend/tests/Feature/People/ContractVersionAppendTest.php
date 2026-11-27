<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Lifecycle\Models\EmploymentContractVersion;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use Tests\Support\PeopleTestCase;

final class ContractVersionAppendTest extends PeopleTestCase
{
    public function test_renew_amend_terminate_append_versions_and_replay_does_not_duplicate(): void
    {
        $employment = $this->employment('VER-0001');
        $source = $this->contract($employment, 'VER-1');

        $renew = [
            'contract_no' => 'VER-2',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'signed_on' => '2026-12-15',
            'status' => 'ACTIVE',
            'renewal_of_contract_id' => $source->id,
            'reason' => '合成续签',
        ];

        $renewed = $this->postJson("/v1/employments/{$employment->id}/contracts", $renew, [
            'Idempotency-Key' => 'renew-1',
        ])->assertCreated()
            ->assertJsonPath('data.previous_contract_id', $source->id)
            ->assertJsonPath('data.version', 2);

        $renewalId = (int) $renewed->json('data.id');

        $replay = $this->postJson("/v1/employments/{$employment->id}/contracts", $renew, [
            'Idempotency-Key' => 'renew-1',
        ])->assertOk();

        $replay->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($renewalId, (int) $replay->json('data.id'));
        $this->assertSame(2, EmploymentContractVersion::query()->where('employment_id', $employment->id)->count());
        $this->assertDatabaseHas('employment_contracts', [
            'id' => $source->id,
            'status' => 'RENEWED',
            'renewed_by_id' => $renewalId,
            'contract_no' => 'VER-1',
        ]);

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            ...$renew,
            'contract_no' => 'VER-2-MISMATCH',
        ], ['Idempotency-Key' => 'renew-1'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame(2, EmploymentContractVersion::query()->where('employment_id', $employment->id)->count());

        $amendPayload = [
            'effective_date' => '2027-06-01',
            'reason' => '合成变更',
            'changes' => [
                'end_date' => '2028-06-30',
                'work_location' => '苏州合成办公室',
                'probation_end_date' => '2027-03-01',
            ],
        ];

        $amended = $this->postJson("/v1/employment-contracts/{$renewalId}/amendments", $amendPayload, [
            'Idempotency-Key' => 'amend-1',
        ])->assertCreated()
            ->assertJsonPath('data.contract.end_date', '2028-06-30')
            ->assertJsonPath('data.contract.work_location', '苏州合成办公室')
            ->assertJsonPath('data.version.change_type', 'AMENDMENT');

        $amendmentId = (int) $amended->json('data.version.id');

        $this->postJson("/v1/employment-contracts/{$renewalId}/amendments", $amendPayload, [
            'Idempotency-Key' => 'amend-1',
        ])->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($amendmentId, (int) EmploymentContractVersion::query()
            ->where('contract_id', $renewalId)
            ->where('change_type', 'AMENDMENT')
            ->value('id'));
        $this->assertSame(1, EmploymentContractVersion::query()
            ->where('contract_id', $renewalId)
            ->where('change_type', 'AMENDMENT')
            ->count());

        $terminatePayload = [
            'effective_date' => '2027-09-30',
            'reason' => '合成终止',
        ];

        $terminated = $this->postJson("/v1/employment-contracts/{$renewalId}/terminate", $terminatePayload, [
            'Idempotency-Key' => 'terminate-1',
        ])->assertOk()
            ->assertJsonPath('data.contract.status', 'TERMINATED')
            ->assertJsonPath('data.version.change_type', 'TERMINATION');

        $terminationId = (int) $terminated->json('data.version.id');

        $this->postJson("/v1/employment-contracts/{$renewalId}/terminate", $terminatePayload, [
            'Idempotency-Key' => 'terminate-1',
        ])->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($terminationId, (int) EmploymentContractVersion::query()
            ->where('contract_id', $renewalId)
            ->where('change_type', 'TERMINATION')
            ->value('id'));
        $this->assertSame(1, EmploymentContractVersion::query()
            ->where('contract_id', $renewalId)
            ->where('change_type', 'TERMINATION')
            ->count());

        // Every original row is still present and immutable in identity terms;
        // only its current status/end date changes, and a new version records
        // the before/after facts.
        $this->assertDatabaseHas('employment_contracts', [
            'id' => $source->id,
            'contract_no' => 'VER-1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $history = $this->getJson("/v1/employment-contracts/{$renewalId}/amendments")
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.items.0.change_type', 'RENEWAL')
            ->assertJsonPath('data.items.1.change_type', 'AMENDMENT')
            ->assertJsonPath('data.items.2.change_type', 'TERMINATION')
            ->json('data.items');

        $amendment = $history[1]['snapshot'];
        $this->assertSame('苏州合成办公室', $amendment['after']['work_location']);
        $this->assertSame('苏州合成办公室', $amendment['changes']['work_location']);
        $this->assertSame('2028-06-30', $amendment['changes']['end_date']);
    }

    public function test_terminated_contract_can_be_neither_renewed_amended_nor_terminated_again(): void
    {
        $employment = $this->employment('VER-BLOCK-1');
        $contract = $this->contract($employment, 'VER-BLOCK-C1');

        $this->postJson("/v1/employment-contracts/{$contract->id}/terminate", [
            'effective_date' => '2026-09-30',
            'reason' => '合成终止锁定',
        ], ['Idempotency-Key' => 'block-terminate-1'])->assertOk();

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'VER-BLOCK-C2',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'renewal_of_contract_id' => $contract->id,
            'reason' => '非法续签已终止合同',
        ], ['Idempotency-Key' => 'block-renew-1'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'CONTRACT_NOT_RENEWABLE');

        $this->assertSame(2, EmploymentContractVersion::query()->where('contract_id', $contract->id)->count());

        $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", [
            'effective_date' => '2026-10-01',
            'reason' => '非法变更已终止合同',
            'changes' => ['end_date' => '2027-01-01'],
        ], ['Idempotency-Key' => 'block-amend-1'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'CONTRACT_NOT_AMENDABLE');

        $this->postJson("/v1/employment-contracts/{$contract->id}/terminate", [
            'effective_date' => '2026-10-01',
            'reason' => '非法重复终止',
        ], ['Idempotency-Key' => 'block-terminate-2'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'CONTRACT_NOT_TERMINABLE');

        // @phpstan-ignore-next-line Larastan resolves this aggregate to a literal count.
        $this->assertSame(2, EmploymentContractVersion::query()->where('contract_id', $contract->id)->count());
    }

    public function test_a_second_renewal_of_the_same_source_is_rejected_without_an_extra_version(): void
    {
        $employment = $this->employment('VER-DOUBLE-1');
        $source = $this->contract($employment, 'VER-DOUBLE-C1');

        $payload = [
            'contract_no' => 'VER-DOUBLE-C2',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'renewal_of_contract_id' => $source->id,
            'reason' => '合成首次续签',
        ];

        $this->postJson("/v1/employments/{$employment->id}/contracts", $payload, [
            'Idempotency-Key' => 'double-renew-1',
        ])->assertCreated();

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            ...$payload,
            'contract_no' => 'VER-DOUBLE-C3',
        ], ['Idempotency-Key' => 'double-renew-2'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'CONTRACT_NOT_RENEWABLE');

        $this->assertSame(2, EmploymentContractVersion::query()->where('employment_id', $employment->id)->count());
        $this->assertSame(2, EmploymentContract::query()->where('employment_id', $employment->id)->count());
    }

    private function employment(string $employeeNo): Employment
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(['legal_name' => "合成员工 {$employeeNo}"]), $employeeNo);
        $scenario->assignment($employment, $scenario->orgUnit('VER-ORG-'.$employeeNo));

        return $employment;
    }

    private function contract(Employment $employment, string $contractNo): EmploymentContract
    {
        $created = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => $contractNo,
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'signed_on' => '2025-12-20',
            'status' => 'ACTIVE',
        ], ['Idempotency-Key' => 'seed-'.$contractNo])->assertCreated();

        return EmploymentContract::query()->whereKey((int) $created->json('data.id'))->firstOrFail();
    }
}
