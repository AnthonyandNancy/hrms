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
        $sales = $scenario->orgUnit('T-SALES');
        $platform = $scenario->orgUnit('T-PLATFORM');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'TR-FUT-1', ['valid_from' => '2026-01-01']);
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
        $before = $directory->assignmentAsOf($employment->id, '2026-11-30');
        $this->assertNotNull($before);
        $this->assertSame($sales->id, $before->orgUnitId);

        $this->approveCase($caseId, 'transfer-future-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $before = $directory->assignmentAsOf($employment->id, '2026-11-30');
        $this->assertNotNull($before);
        $this->assertSame($sales->id, $before->orgUnitId);
        $after = $directory->assignmentAsOf($employment->id, '2026-12-01');
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

    public function test_cross_department_transfer_keeps_the_employment_and_is_idempotent(): void
    {
        $scenario = $this->peopleScenario;
        $sales = $scenario->orgUnit('XD-SALES');
        $platform = $scenario->orgUnit('XD-PLATFORM');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'XD-0001', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $sales, ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $platform->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Cross-department move',
            'submit' => true,
        ], 'cross-department-create')->assertCreated();

        $caseId = (int) $created->json('data.case_id');
        $this->approveCase($caseId, 'cross-department-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $directory = app(EmploymentLifecycleDirectory::class);
        $this->assertSame($sales->id, $directory->assignmentAsOf($employment->id, '2026-11-30')?->orgUnitId);
        $this->assertSame($platform->id, $directory->assignmentAsOf($employment->id, '2026-12-01')?->orgUnitId);

        $before = Employment::query()->findOrFail($employment->id);
        $this->assertSame('ACTIVE', $before->status);
        $this->assertCount(1, Employment::query()->whereKey($employment->id)->get());

        // Replaying the same effect leaves exactly one new assignment and no
        // additional employment row: effective-dated effects stay idempotent.
        $this->approveCase($caseId, 'cross-department-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertCount(1, Employment::query()->whereKey($employment->id)->get());
        $this->assertSame(2, Assignment::query()->where('employment_id', $employment->id)->count());
    }
}
