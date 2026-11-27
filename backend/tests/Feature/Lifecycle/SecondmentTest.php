<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\People\Models\Assignment;
use Tests\Support\LifecycleTestCase;

final class SecondmentTest extends LifecycleTestCase
{
    public function test_secondment_creates_parallel_assignment_and_returns_to_primary(): void
    {
        [$employment, $homeOrg, $hostOrg] = $this->seedEmploymentWithOrg();

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'Synthetic secondment',
        ], ['Idempotency-Key' => 'sec-1'])->assertCreated()
            ->assertJsonPath('data.assignment_type', 'SECONDMENT')
            ->assertJsonPath('data.is_primary', false)
            ->assertJsonPath('data.host_org_unit_id', $hostOrg->id);

        $primary = Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('assignment_type', 'PRIMARY')
            ->first();

        $this->assertNotNull($primary);
        $this->assertSame(1, Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('assignment_type', 'PRIMARY')
            ->count());

        $this->assertSame($homeOrg->id, (int) $primary->org_unit_id);
        $this->assertSame('9999-12-31', (string) $primary->valid_to);
        $this->assertNull($primary->superseded_at);

        $secondment = Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('assignment_type', 'SECONDMENT')
            ->first();

        $this->assertNotNull($secondment);
        $this->assertFalse((bool) $secondment->is_primary);
        $this->assertNull($secondment->manager_assignment_id);
        $this->assertSame('2026-12-01', (string) $secondment->valid_from);
        $this->assertSame('2027-03-31', (string) $secondment->valid_to);

        $this->getJson('/v1/lifecycle/secondments?employment_id='.$employment->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.assignment_type', 'SECONDMENT')
            ->assertJsonPath('data.items.0.assignment_id', (int) $secondment->id);
    }

    public function test_secondment_replays_idempotently_and_rejects_changed_payload(): void
    {
        [$employment, , $hostOrg] = $this->seedEmploymentWithOrg();

        $payload = [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'Synthetic secondment',
        ];

        $created = $this->postJson('/v1/lifecycle/secondments', $payload, ['Idempotency-Key' => 'sec-replay'])
            ->assertCreated();

        $this->postJson('/v1/lifecycle/secondments', $payload, ['Idempotency-Key' => 'sec-replay'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.assignment_id', (int) $created->json('data.assignment_id'));

        $payload['reason'] = 'Different reason under the same key';

        $this->postJson('/v1/lifecycle/secondments', $payload, ['Idempotency-Key' => 'sec-replay'])
            ->assertStatus(409);

        $this->assertSame(1, Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('assignment_type', 'SECONDMENT')
            ->count());
    }

    public function test_secondment_requires_idempotency_key_and_rejects_overlap(): void
    {
        [$employment, , $hostOrg] = $this->seedEmploymentWithOrg();

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'Synthetic secondment',
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'First secondment',
        ], ['Idempotency-Key' => 'sec-overlap-1'])->assertCreated();

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-06-30',
            'reason' => 'Overlapping secondment',
        ], ['Idempotency-Key' => 'sec-overlap-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'SECONDMENT_OVERLAP');
    }

    public function test_secondment_rejects_invalid_ranges_and_missing_primary(): void
    {
        [$employment, , $hostOrg] = $this->seedEmploymentWithOrg();

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2027-03-31',
            'effective_to' => '2026-12-01',
            'reason' => 'Backwards range',
        ], ['Idempotency-Key' => 'sec-invalid-range'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'SECONDMENT_INVALID_RANGE');

        $other = $this->peopleScenario->employment($this->peopleScenario->person(), 'SEC-0002');

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $other->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'No primary assignment',
        ], ['Idempotency-Key' => 'sec-no-primary'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'SECONDMENT_PRIMARY_MISSING');
    }

    public function test_secondment_denies_missing_capability_and_unknown_employment(): void
    {
        [$employment, , $hostOrg] = $this->seedEmploymentWithOrg();

        $this->authorizer->deniedCapabilities = ['lifecycle.secondment.manage'];

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'Denied secondment',
        ], ['Idempotency-Key' => 'sec-denied'])->assertForbidden();

        $this->authorizer->deniedCapabilities = [];
        $this->actAs(999);

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => 999_999,
            'host_org_unit_id' => $hostOrg->id,
            'effective_from' => '2026-12-01',
            'effective_to' => '2027-03-31',
            'reason' => 'Unknown employment secondment',
        ], ['Idempotency-Key' => 'sec-unknown-employment'])->assertNotFound();
    }
}
