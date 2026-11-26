<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmploymentContract;
use Tests\Support\PeopleTestCase;

final class ContractSoftDeleteTest extends PeopleTestCase
{
    public function test_contract_delete_is_soft_audited_and_can_be_restored(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'SOFT-0001');
        $contract = EmploymentContract::query()->create([
            'tenant_id' => 1,
            'employment_id' => (int) $employment->id,
            'contract_no' => 'CON-SOFT-001',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
            'version' => 1,
            'legal_entity_id' => (string) $entity->id,
            'terms_summary' => 'Synthetic contract',
            'recorded_at' => '2026-01-01 00:00:00',
        ]);

        $this->deleteJson("/v1/employment-contracts/{$contract->id}", [
            'reason' => 'Synthetic duplicate entry',
        ], ['Idempotency-Key' => 'contract-delete-1'])->assertOk();

        $this->assertSoftDeleted('employment_contracts', ['id' => $contract->id]);
        $deleted = EmploymentContract::withTrashed()->findOrFail($contract->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame('Synthetic duplicate entry', (string) $deleted->delete_reason);
        $this->assertSame(10, (int) $deleted->deleted_by);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'people.contract.soft_deleted',
            'aggregate_id' => (string) $contract->id,
        ]);

        $this->postJson("/v1/employment-contracts/{$contract->id}/restore", [], [
            'Idempotency-Key' => 'contract-restore-1',
        ])->assertOk();

        $restored = EmploymentContract::query()->findOrFail($contract->id);
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->deleted_by);
        $this->assertNull($restored->delete_reason);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'people.contract.restored',
            'aggregate_id' => (string) $contract->id,
        ]);
    }

    public function test_default_contract_ledger_hides_soft_deleted_rows(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'SOFT-0002');
        $scenario->assignment($employment, $scenario->orgUnit($entity, 'SOFT-ORG'));
        $contract = EmploymentContract::query()->create([
            'tenant_id' => 1,
            'employment_id' => (int) $employment->id,
            'contract_no' => 'CON-SOFT-002',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
            'version' => 1,
            'legal_entity_id' => (string) $entity->id,
            'terms_summary' => 'Synthetic hidden contract',
            'recorded_at' => '2026-01-01 00:00:00',
        ]);
        $contract->forceFill(['deleted_by' => 10, 'delete_reason' => 'Synthetic soft delete'])->save();
        $contract->delete();

        $this->getJson('/v1/employments/contracts?as_of=2026-11-25')->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonMissing(['contract_no' => 'CON-SOFT-002']);
    }
}
