<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Lifecycle\Models\EmploymentContractVersion;
use App\Modules\People\Models\EmploymentContract;
use Tests\Support\PeopleTestCase;

final class ContractLifecycleTest extends PeopleTestCase
{
    public function test_contract_can_be_amended_and_terminated_without_overwriting_history(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('CONTRACT-ORG');
        $employment = $scenario->employment($scenario->person(), 'CON-0001');
        $scenario->assignment($employment, $org);

        $contract = $this->contract($employment->id, 'C-0001');

        $amended = $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", [
            'effective_date' => '2026-06-01',
            'reason' => 'Synthetic amendment',
            'changes' => ['end_date' => '2027-12-31'],
        ], ['Idempotency-Key' => 'amend-1'])->assertCreated()
            ->assertJsonPath('data.contract.status', 'ACTIVE')
            ->assertJsonPath('data.contract.end_date', '2027-12-31')
            ->assertJsonPath('data.version.change_type', 'AMENDMENT');

        $amendmentId = (int) $amended->json('data.version.id');

        $this->postJson("/v1/employment-contracts/{$contract->id}/terminate", [
            'effective_date' => '2026-09-30',
            'reason' => 'Synthetic termination',
        ], ['Idempotency-Key' => 'term-1'])->assertOk()
            ->assertJsonPath('data.contract.status', 'TERMINATED')
            ->assertJsonPath('data.contract.terminated_on', '2026-09-30')
            ->assertJsonPath('data.version.change_type', 'TERMINATION');

        $this->assertDatabaseHas('employment_contract_versions', [
            'contract_id' => $contract->id,
            'change_type' => 'AMENDMENT',
            'effective_from' => '2026-06-01',
        ]);
        $this->assertDatabaseHas('employment_contract_versions', [
            'contract_id' => $contract->id,
            'change_type' => 'TERMINATION',
            'effective_from' => '2026-09-30',
        ]);
        $this->assertSame(2, EmploymentContractVersion::query()->where('contract_id', $contract->id)->count());
        $this->assertSame('AMENDMENT', EmploymentContractVersion::query()->whereKey($amendmentId)->value('change_type'));
        $this->assertSame('TERMINATED', $contract->refresh()->status);
        $this->assertSame('2026-09-30', (string) $contract->terminated_on);
        $this->assertSame('2026-09-30', (string) $contract->end_date);
    }

    public function test_ledger_and_amendment_history_use_the_projection_envelope(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'LEDGER-0001');
        $scenario->assignment($employment, $scenario->orgUnit('LEDGER-ORG'));
        $contract = $this->contract($employment->id, 'LEDGER-C-1');

        $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", [
            'effective_date' => '2026-06-01',
            'reason' => 'Synthetic ledger amendment',
            'changes' => ['terms_summary' => 'Updated synthetic terms'],
        ], ['Idempotency-Key' => 'ledger-amend-1'])->assertCreated();

        $this->getJson('/v1/employments/contracts?page=1&page_size=20&as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 20)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.as_of', '2026-11-25')
            ->assertJsonPath('data.freshness', 'LIVE')
            ->assertJsonPath('data.partial', false)
            ->assertJsonPath('data.items.0.id', $contract->id)
            ->assertJsonStructure(['data' => [
                'items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash', 'partial', 'partial_reason', 'drilldown',
            ]]);

        $this->getJson("/v1/employment-contracts/{$contract->id}/amendments")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.change_type', 'AMENDMENT')
            ->assertJsonPath('data.freshness', 'LIVE');
    }

    public function test_contract_mutations_are_idempotent_and_require_a_key(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'IDEM-CON');
        $contract = $this->contract($employment->id, 'IDEM-C-1');

        $payload = [
            'effective_date' => '2026-06-01',
            'reason' => 'Synthetic idempotent amendment',
            'changes' => ['terms_summary' => 'Version one'],
        ];

        $first = $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", $payload, [
            'Idempotency-Key' => 'contract-amend-idem',
        ])->assertCreated();

        $replay = $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", $payload, [
            'Idempotency-Key' => 'contract-amend-idem',
        ])->assertOk();

        $replay->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.version.id'), $replay->json('data.version.id'));
        $this->assertSame(1, EmploymentContractVersion::query()->where('contract_id', $contract->id)->count());

        $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", [
            ...$payload,
            'reason' => 'Different synthetic payload',
        ], ['Idempotency-Key' => 'contract-amend-idem'])->assertConflict()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", $payload)
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_contract_templates_are_versioned_and_publish_supersedes_the_previous_version(): void
    {
        $first = $this->postJson('/v1/contract-templates', [
            'code' => 'CN-FIXED',
            'name' => 'Synthetic fixed-term template',
            'body' => 'Synthetic body version 1',
        ], ['Idempotency-Key' => 'template-create-1'])->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1);

        $firstId = (int) $first->json('data.id');

        $second = $this->postJson('/v1/contract-templates', [
            'code' => 'CN-FIXED',
            'name' => 'Synthetic fixed-term template v2',
            'body' => 'Synthetic body version 2',
        ], ['Idempotency-Key' => 'template-create-2'])->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 2);

        $secondId = (int) $second->json('data.id');

        $this->postJson("/v1/contract-templates/{$firstId}/publish", [], ['Idempotency-Key' => 'template-publish-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        $this->postJson("/v1/contract-templates/{$secondId}/publish", [], ['Idempotency-Key' => 'template-publish-2'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        $this->assertDatabaseHas('contract_templates', ['id' => $firstId, 'status' => 'ARCHIVED']);
        $this->assertDatabaseHas('contract_templates', ['id' => $secondId, 'status' => 'PUBLISHED']);

        $this->getJson('/v1/contract-templates?page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.code', 'CN-FIXED')
            ->assertJsonPath('data.freshness', 'LIVE')
            ->assertJsonStructure(['data' => [
                'items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash', 'partial', 'partial_reason', 'drilldown',
            ]]);
    }

    public function test_out_of_scope_contract_mutation_is_not_found_and_unknown_changes_are_rejected(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'SCOPE-CON');
        $contract = $this->contract($employment->id, 'SCOPE-C-1');

        $this->postJson("/v1/employment-contracts/{$contract->id}/amendments", [
            'effective_date' => '2026-06-01',
            'reason' => 'Unsupported change',
            'changes' => ['status' => 'RENEWED'],
        ], ['Idempotency-Key' => 'unsupported-change'])->assertStatus(422);

        $this->actAs(userId: 999);
        $this->authorizer->deniedCapabilities = ['people.contract.terminate'];

        $this->postJson("/v1/employment-contracts/{$contract->id}/terminate", [
            'effective_date' => '2026-09-30',
            'reason' => 'Out-of-scope termination',
        ], ['Idempotency-Key' => 'cross-scope-term'])->assertForbidden();
    }

    private function contract(int $employmentId, string $contractNo): EmploymentContract
    {
        return EmploymentContract::query()->create([
            'employment_id' => $employmentId,
            'contract_no' => $contractNo,
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);
    }
}
