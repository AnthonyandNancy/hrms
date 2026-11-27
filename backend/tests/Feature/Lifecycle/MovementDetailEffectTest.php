<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\People\Models\Assignment;
use Tests\Support\LifecycleTestCase;

/**
 * M07 Task 3 contract: detail is frozen, the timeline is Workflow-owned, and
 * replayed effects do not append a second People history row.
 */
final class MovementDetailEffectTest extends LifecycleTestCase
{
    public function test_detail_returns_same_case_before_and_after_snapshot_and_effect_is_idempotent(): void
    {
        [$caseId, $employmentId] = $this->createApprovedTransfer();

        $first = $this->getJson("/v1/lifecycle/cases/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.case_id', $caseId)
            ->assertJsonPath('data.kind', 'transfer')
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'APPLIED')
            ->assertJsonPath('data.domain_status', 'EFFECTIVE')
            ->assertJsonPath('data.effective_date', '2026-12-01')
            ->assertJsonPath('data.before.employment_id', $employmentId)
            ->assertJsonPath('data.before.assignment.assignment_id', fn (mixed $value): bool => is_int($value) && $value > 0)
            ->assertJsonPath('data.after.assignment_id', fn (mixed $value): bool => is_int($value) && $value > 0)
            ->assertJsonPath('data.after.org_unit_id', fn (mixed $value): bool => is_int($value) && $value > 0);

        $second = $this->getJson("/v1/lifecycle/cases/{$caseId}")->assertOk();

        $this->assertSame($first->json('data.effect_status'), $second->json('data.effect_status'));
        $this->assertSame($first->json('data.after.assignment_id'), $second->json('data.after.assignment_id'));
        $this->assertSame($first->json('data.before'), $second->json('data.before'));
        $this->assertSame(2, Assignment::query()->where('employment_id', $employmentId)->count());

        // Repeating the same approval is a real state-machine conflict, not a
        // second effect. It must not append another assignment.
        $this->approveCase($caseId, 'm07-detail-replay')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'INVALID_CASE_TRANSITION');
        // @phpstan-ignore-next-line Larastan resolves this aggregate to a literal count.
        $this->assertSame(2, Assignment::query()->where('employment_id', $employmentId)->count());

        $intent = LifecycleIntent::query()->where('case_id', $caseId)->firstOrFail();
        $this->assertSame('EFFECTIVE', $intent->status);
        $this->assertNotNull($intent->detail_frozen_at);
        $this->assertNotNull($intent->before_json);
        $this->assertNotNull($intent->after_json);
        $this->assertSame('2026-12-01', (string) $intent->planned_effective_on);
    }

    public function test_pending_approval_detail_has_no_people_mutation_and_no_fabricated_snapshot(): void
    {
        $scenario = $this->peopleScenario;
        $from = $scenario->orgUnit('DET-FROM');
        $to = $scenario->orgUnit('DET-TO');
        $employment = $scenario->employment($scenario->person(), 'DET-0001', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $from, ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $to->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Synthetic pending detail transfer',
            'submit' => true,
        ], 'm07-pending-detail-create')->assertCreated();
        $caseId = (int) $created->json('data.case_id');

        $this->getJson("/v1/lifecycle/cases/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.application_status', 'SUBMITTED')
            ->assertJsonPath('data.effect_status', 'NONE')
            ->assertJsonPath('data.before', [])
            ->assertJsonPath('data.after', [])
            ->assertJsonPath('data.effect_result', []);

        $this->assertSame(1, Assignment::query()->where('employment_id', $employment->id)->count());
    }

    /**
     * @return array{0:int,1:int}
     */
    private function createApprovedTransfer(): array
    {
        $scenario = $this->peopleScenario;
        $from = $scenario->orgUnit('DET-OK-FROM');
        $to = $scenario->orgUnit('DET-OK-TO');
        $employment = $scenario->employment($scenario->person(), 'DET-OK-0001', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $from, ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $to->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Synthetic frozen detail transfer',
            'submit' => true,
        ], 'm07-detail-create')->assertCreated();
        $caseId = (int) $created->json('data.case_id');

        $this->approveCase($caseId, 'm07-detail-approve')
            ->assertOk()
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'APPLIED');

        return [$caseId, (int) $employment->id];
    }
}
