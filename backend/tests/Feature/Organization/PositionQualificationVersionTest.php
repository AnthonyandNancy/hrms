<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Models\PositionQualification;
use Tests\Support\PeopleTestCase;

/**
 * M06 qualification requirements are append-only versions. Replaying one
 * idempotency key must return the stored version and create neither a second
 * version nor a second audit entry.
 */
final class PositionQualificationVersionTest extends PeopleTestCase
{
    public function test_replay_returns_the_original_version_without_duplicate_audit(): void
    {
        $position = $this->position('QUAL-1');
        $payload = [
            'requirements' => ['skills' => '合成技能', 'qualifications' => '合成资格'],
            'effective_from' => '2026-12-01',
        ];

        $first = $this->postJson(
            "/v1/positions/{$position->id}/requirements",
            $payload,
            ['Idempotency-Key' => 'qual-1'],
        )->assertCreated()->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'org.position.qualification.versioned',
            'aggregate_type' => 'position_qualification',
            'aggregate_id' => (string) $first->json('data.id'),
        ]);

        $second = $this->postJson(
            "/v1/positions/{$position->id}/requirements",
            $payload,
            ['Idempotency-Key' => 'qual-1'],
        )->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(
            1,
            PositionQualification::query()->where('position_id', $position->id)->count(),
        );
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_a_new_version_preserves_the_prior_version(): void
    {
        $position = $this->position('QUAL-2');

        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['skills' => '旧技能'],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'qual-2-v1'])->assertCreated();

        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['skills' => '新技能'],
            'effective_from' => '2027-01-01',
        ], ['Idempotency-Key' => 'qual-2-v2'])->assertCreated();

        $this->assertSame(2, PositionQualification::query()->where('position_id', $position->id)->count());
        $this->assertDatabaseHas('position_qualifications', [
            'position_id' => (int) $position->id,
            'version' => 1,
            'effective_from' => '2026-12-01',
        ]);
        $this->assertDatabaseHas('position_qualifications', [
            'position_id' => (int) $position->id,
            'version' => 2,
            'effective_from' => '2027-01-01',
        ]);

        $first = PositionQualification::query()
            ->where('position_id', $position->id)
            ->where('version', 1)
            ->firstOrFail();

        $this->assertSame(['skills' => '旧技能'], $first->requirements_json);
    }

    public function test_same_key_different_payload_is_a_conflict_and_writes_nothing(): void
    {
        $position = $this->position('QUAL-3');

        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['skills' => 'A'],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'qual-3'])->assertCreated();

        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['skills' => 'B'],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'qual-3'])->assertConflict();

        $this->assertSame(1, PositionQualification::query()->where('position_id', $position->id)->count());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_qualification_write_requires_its_capability_key_and_existing_position(): void
    {
        $position = $this->position('QUAL-4');

        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['skills' => 'A'],
            'effective_from' => '2026-12-01',
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->authorizer->deniedCapabilities = ['org.position.qualification.manage'];
        $this->postJson("/v1/positions/{$position->id}/requirements", [
            'requirements' => ['skills' => 'A'],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'qual-4-denied'])->assertForbidden();

        $this->authorizer->deniedCapabilities = [];
        $this->postJson('/v1/positions/999999/requirements', [
            'requirements' => ['skills' => 'A'],
            'effective_from' => '2026-12-01',
        ], ['Idempotency-Key' => 'qual-4-missing'])->assertNotFound();
    }

    private function position(string $code): Position
    {
        $scenario = $this->scenario();

        return $scenario->position($scenario->orgUnit($code.'-ORG'), $code);
    }
}
