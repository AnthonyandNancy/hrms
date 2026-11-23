<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use Tests\Support\LifecycleTestCase;

final class TransferFutureEffectTest extends LifecycleTestCase
{
    public function test_future_transfer_keeps_todays_view_and_preserves_assignment_history(): void
    {
        $scenario = $this->peopleScenario;
        $entity = $scenario->legalEntity();
        $sales = $scenario->orgUnit($entity, 'T-SALES');
        $platform = $scenario->orgUnit($entity, 'T-PLATFORM');
        $person = $scenario->person();
        $employment = $scenario->employment($person, $entity, 'TR-FUT-1', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $sales, ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $platform->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Department move',
            'submit' => true,
        ], 'transfer-future-create')->assertCreated();

        $caseId = (int) $created->json('data.case_id');

        $directory = app(EmploymentLifecycleDirectory::class);
        $before = $directory->assignmentAsOf(1, $employment->id, '2026-11-30');
        $this->assertNotNull($before);
        $this->assertSame($sales->id, $before->orgUnitId);

        $this->approveCase($caseId, 'transfer-future-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $before = $directory->assignmentAsOf(1, $employment->id, '2026-11-30');
        $this->assertNotNull($before);
        $this->assertSame($sales->id, $before->orgUnitId);
        $after = $directory->assignmentAsOf(1, $employment->id, '2026-12-01');
        $this->assertNotNull($after);
        $this->assertSame($platform->id, $after->orgUnitId);

        $old = Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('org_unit_id', $sales->id)
            ->firstOrFail();
        $this->assertSame('2026-12-01', (string) $old->valid_to);

        $all = Assignment::query()->where('employment_id', $employment->id)->orderBy('valid_from')->get();
        $this->assertCount(2, $all);
    }

    public function test_cross_entity_transfer_creates_new_employment_and_leaves_old_terminated(): void
    {
        $scenario = $this->peopleScenario;
        $cn = $scenario->legalEntity('DEMO-CN');
        $sg = $scenario->legalEntity('DEMO-SG', 'Demo Singapore', 'Asia/Singapore', 'SGD');
        $sgOps = $scenario->orgUnit($sg, 'SG-OPS');
        $person = $scenario->person();
        $old = $scenario->employment($person, $cn, 'X-ENTITY-1', ['valid_from' => '2026-01-01']);
        $scenario->assignment($old, $scenario->orgUnit($cn, 'CN-OPS'), ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $old->id,
            'legal_entity_id' => $sg->id,
            'org_unit_id' => $sgOps->id,
            'employee_no' => 'SG-ENTITY-1',
            'effective_date' => '2026-12-01',
            'reason' => 'Group transfer',
            'submit' => true,
        ], 'cross-entity-create')->assertCreated();

        $caseId = (int) $created->json('data.case_id');
        $this->approveCase($caseId, 'cross-entity-approve')->assertOk();

        $old->refresh();
        $this->assertSame('TERMINATED', $old->status);
        $this->assertSame('2026-12-01', (string) $old->valid_to);

        $new = Employment::query()->where('source_type', 'TRANSFER')->where('tenant_id', 1)->firstOrFail();
        $this->assertSame($person->id, $new->person_id);
        $this->assertSame($sg->id, $new->legal_entity_id);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('2026-12-01', (string) $new->valid_from);
        $this->assertSame($old->seniority_start_date, $new->seniority_start_date);
    }
}
