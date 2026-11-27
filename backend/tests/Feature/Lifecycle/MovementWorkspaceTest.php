<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use Tests\Support\LifecycleTestCase;

/**
 * Task 7 backend contract evidence for the employee movement workspace.
 *
 * The workspace is a read/orchestration surface over the existing Case
 * commands. These tests pin the security-critical semantics the UI depends on:
 * no People state changes before approval, effective dates travel in the case
 * payload, server-side date ordering returns 422, missing capabilities are
 * denied, and a decided case cannot be decided or withdrawn again.
 */
final class MovementWorkspaceTest extends LifecycleTestCase
{
    public function test_transfer_only_applies_after_approval_and_exposes_effective_date(): void
    {
        $scenario = $this->peopleScenario;
        $sales = $scenario->orgUnit('MW-SALES');
        $platform = $scenario->orgUnit('MW-PLATFORM');
        $employment = $scenario->employment($scenario->person(), 'MW-0001', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $sales, ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $platform->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Synthetic movement workspace transfer',
            'submit' => true,
        ], 'mw-transfer-create')->assertCreated();

        $caseId = (int) $created->json('data.case_id');

        // The pending case exists, but no People assignment has been written.
        $this->assertSame(1, Assignment::query()->where('employment_id', $employment->id)->count());
        $this->assertDatabaseHas('lifecycle_intents', [
            'case_id' => $caseId,
            'case_type' => 'ASSIGNMENT_CHANGE',
            'employment_id' => $employment->id,
        ]);

        $this->getJson("/v1/lifecycle/cases/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.case_type', 'ASSIGNMENT_CHANGE')
            ->assertJsonPath('data.application_status', 'SUBMITTED')
            ->assertJsonPath('data.effect_status', 'NONE')
            ->assertJsonPath('data.payload.effective_date', '2026-12-01')
            ->assertJsonPath('data.employment.employment_id', $employment->id);

        $this->getJson('/v1/lifecycle/cases?case_type=ASSIGNMENT_CHANGE&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.case_id', $caseId)
            ->assertJsonPath('data.items.0.employment_id', $employment->id);

        $this->approveCase($caseId, 'mw-transfer-approve')
            ->assertOk()
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertDatabaseHas('assignments', [
            'employment_id' => $employment->id,
            'org_unit_id' => $platform->id,
            'valid_from' => '2026-12-01',
            'source_type' => 'TRANSFER',
            'source_id' => $caseId,
        ]);

        $intent = LifecycleIntent::query()->where('case_id', $caseId)->firstOrFail();
        $this->assertNotNull($intent->effective_at);

        $this->getJson("/v1/lifecycle/cases/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.domain_status', 'EFFECTIVE')
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'APPLIED');
    }

    public function test_invalid_secondment_date_order_returns_field_level_422(): void
    {
        [$employment, , $host] = $this->seedEmploymentWithOrg();

        $this->createLifecycleCase('secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $host->id,
            'effective_from' => '2027-03-31',
            'effective_to' => '2026-12-01',
            'reason' => 'Synthetic backwards secondment range',
        ], 'mw-secondment-invalid')->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.reason', 'SECONDMENT_INVALID_RANGE')
            ->assertJsonPath('data.fields.effective_to.0', 'The secondment end date must be after the start date.');

        $this->assertSame(1, Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('assignment_type', 'PRIMARY')
            ->count());
        $this->assertSame(0, Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('assignment_type', 'SECONDMENT')
            ->count());
    }

    public function test_movement_creation_and_case_read_require_their_capabilities(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('MW-AUTH');
        $target = $scenario->orgUnit('MW-AUTH-TARGET');
        $employment = $scenario->employment($scenario->person(), 'MW-AUTH-1', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $org, ['valid_from' => '2026-01-01']);
        $this->actAs(11);

        $this->authorizer->deniedCapabilities = ['lifecycle.transfer.manage'];

        $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $target->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Denied transfer',
            'submit' => true,
        ], 'mw-denied-transfer')->assertForbidden();

        $this->assertSame(1, Assignment::query()->where('employment_id', $employment->id)->count());

        $this->authorizer->deniedCapabilities = ['lifecycle.case.read'];
        $this->getJson('/v1/lifecycle/cases')->assertForbidden();
    }

    public function test_decided_case_is_read_only_for_further_decisions_and_withdrawal(): void
    {
        $scenario = $this->peopleScenario;
        $from = $scenario->orgUnit('MW-RO-FROM');
        $to = $scenario->orgUnit('MW-RO-TO');
        $employment = $scenario->employment($scenario->person(), 'MW-RO-1', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $from, ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $to->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Synthetic read-only movement',
            'submit' => true,
        ], 'mw-readonly-create')->assertCreated();

        $caseId = (int) $created->json('data.case_id');
        $this->approveCase($caseId, 'mw-readonly-approve')->assertOk();

        $assignmentCount = Assignment::query()->where('employment_id', $employment->id)->count();

        $this->postJson("/v1/cases/{$caseId}/decisions", [
            'action' => 'REJECT',
            'comment' => 'Attempt to decide twice.',
        ], ['Idempotency-Key' => 'mw-readonly-reject'])->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT')
            ->assertJsonPath('data.reason', 'INVALID_CASE_TRANSITION');

        $this->postJson("/v1/cases/{$caseId}/withdraw", [
            'comment' => 'Attempt to withdraw an approved movement.',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT')
            ->assertJsonPath('data.reason', 'CASE_NOT_WITHDRAWABLE');

        $this->assertSame(
            $assignmentCount,
            Assignment::query()->where('employment_id', $employment->id)->count(),
        );
        $this->assertSame('ACTIVE', (string) Employment::query()->findOrFail($employment->id)->status);

        $this->getJson("/v1/lifecycle/cases/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.application_status', 'APPROVED');
    }
}
